<?php
namespace OCA\GoogleContactSync\Service;

use OCP\IConfig;
use OCP\ILogger;
use OCA\IntegrationGoogle\Service\TokenProvider;
use Sabre\VObject;
use OCA\Dav\CardDAV\CardDavBackend;
use Google_Client;
use Google_Service_PeopleService;

class ContactSyncService {
    private $tokenProvider;
    private $config;
    private $logger;
    private $cardDavBackend;

    public function __construct(
        TokenProvider $tokenProvider,
        IConfig $config,
        ILogger $logger,
        CardDavBackend $cardDavBackend
    ) {
        $this->tokenProvider = $tokenProvider;
        $this->config = $config;
        $this->logger = $logger;
        $this->cardDavBackend = $cardDavBackend;
    }

    public function syncAllUsers() {
        try {
            $users = $this->tokenProvider->getUsersWithToken();
        } catch (\Exception $e) {
            $this->logger->error("GoogleContactSync: 无法获取已授权用户列表 - " . $e->getMessage());
            return;
        }

        foreach ($users as $userId) {
            $this->syncUser($userId);
        }
    }

    private function syncUser($userId) {
        $enabled = $this->config->getUserValue($userId, 'google_contact_sync', 'enabled', 'no');
        if ($enabled !== 'yes') {
            return;
        }

        try {
            $accessToken = $this->tokenProvider->getToken($userId);
            if (!$accessToken) {
                return;
            }

            $client = new Google_Client();
            $client->setAccessToken($accessToken);
            if ($client->isAccessTokenExpired()) {
                return;
            }

            $service = new Google_Service_PeopleService($client);
            $addressBookId = $this->getDefaultAddressBookId($userId);
            if (!$addressBookId) {
                $this->logger->warning("GoogleContactSync: 用户 {$userId} 无可用默认通讯录");
                return;
            }

            // 增量同步 Token
            $syncToken = $this->config->getUserValue($userId, 'google_contact_sync', 'sync_token', null);
            $optParams = [
                'personFields' => 'names,nicknames,emailAddresses,phoneNumbers,organizations,addresses,biographies,birthdays,urls,photos',
                'pageSize' => 100,
                'requestSyncToken' => true
            ];
            if ($syncToken) {
                $optParams['syncToken'] = $syncToken;
            }

            $results = $service->people_connections->listPeopleConnections('people/me', $optParams);

            // 处理新增与修改
            foreach ($results->getConnections() ?? [] as $person) {
                $this->upsertContact($addressBookId, $person);
            }

            // 处理已在 Google 侧被删去的联系人 (仅增量触发时有效)
            foreach ($results->getDeletedConnections() ?? [] as $person) {
                $this->deleteContact($addressBookId, $person->getResourceName());
            }

            if ($results->getNextSyncToken()) {
                $this->config->setUserValue($userId, 'google_contact_sync', 'sync_token', $results->getNextSyncToken());
            }
        } catch (\Exception $e) {
            $this->logger->error("GoogleContactSync [用户 {$userId}] 异常: " . $e->getMessage());
            // 如果 syncToken 过期失效 (HTTP 410 Gone)，清除 Token 以便下回强制全量更新
            if (strpos($e->getMessage(), '410') !== false) {
                $this->config->deleteUserValue($userId, 'google_contact_sync', 'sync_token');
            }
        }
    }

    /**
     * 将 Google Person JSON 转换为完整 vCard 格式
     */
    private function upsertContact($addressBookId, \Google_Service_PeopleService_Person $person) {
        $googleId = $person->getResourceName();
        $uri = str_replace('people/', '', $googleId) . '.vcf';

        $vcard = new VObject\Component\VCard(['UID' => $uri]);

        // 1. 姓名 (FN & N)
        if (!empty($person->getNames())) {
            $name = $person->getNames()[0];
            $vcard->FN = $name->getDisplayName() ?? '';
            $vcard->N = [
                $name->getFamilyName() ?? '',
                $name->getGivenName() ?? '',
                $name->getMiddleName() ?? '',
                $name->getHonorificPrefix() ?? '',
                $name->getHonorificSuffix() ?? ''
            ];
        }

        // 2. 昵称 (NICKNAME)
        if (!empty($person->getNicknames())) {
            $vcard->NICKNAME = $person->getNicknames()[0]->getValue();
        }

        // 3. 电话号码 (TEL)
        foreach ($person->getPhoneNumbers() ?? [] as $phone) {
            $vcard->add('TEL', $phone->getValue(), ['TYPE' => strtoupper($phone->getType() ?? 'VOICE')]);
        }

        // 4. 电子邮件 (EMAIL)
        foreach ($person->getEmailAddresses() ?? [] as $email) {
            $vcard->add('EMAIL', $email->getValue(), ['TYPE' => strtoupper($email->getType() ?? 'INTERNET')]);
        }

        // 5. 组织/公司与头衔 (ORG & TITLE)
        if (!empty($person->getOrganizations())) {
            $org = $person->getOrganizations()[0];
            $orgStr = $org->getName() ?? '';
            if ($org->getDepartment()) {
                $orgStr .= ';' . $org->getDepartment();
            }
            $vcard->ORG = $orgStr;
            if ($org->getTitle()) {
                $vcard->TITLE = $org->getTitle();
            }
        }

        // 6. 地址 (ADR)
        foreach ($person->getAddresses() ?? [] as $addr) {
            $vcard->add('ADR', [
                $addr->getPoBox() ?? '',
                $addr->getExtendedAddress() ?? '',
                $addr->getStreetAddress() ?? '',
                $addr->getCity() ?? '',
                $addr->getRegion() ?? '',
                $addr->getPostalCode() ?? '',
                $addr->getCountry() ?? ''
            ], ['TYPE' => strtoupper($addr->getType() ?? 'HOME')]);
        }

        // 7. 备注 (NOTE)
        if (!empty($person->getBiographies())) {
            $vcard->NOTE = $person->getBiographies()[0]->getValue();
        }

        // 8. 生日 (BDAY: ISO-8601)
        if (!empty($person->getBirthdays())) {
            $date = $person->getBirthdays()[0]->getDate();
            if ($date && $date->getYear() && $date->getMonth() && $date->getDay()) {
                $vcard->BDAY = sprintf('%04d-%02d-%02d', $date->getYear(), $date->getMonth(), $date->getDay());
            }
        }

        // 9. 网址 (URL)
        foreach ($person->getUrls() ?? [] as $url) {
            $vcard->add('URL', $url->getValue(), ['TYPE' => strtoupper($url->getType() ?? 'HOMEPAGE')]);
        }

        // 10. 头像链接 (PHOTO URL)
        if (!empty($person->getPhotos())) {
            $photo = $person->getPhotos()[0];
            if (!$photo->getDefault()) {
                $vcard->add('URL', $photo->getUrl(), ['TYPE' => 'PHOTO']);
            }
        }

        $cardData = $vcard->serialize();

        try {
            if ($this->cardDavBackend->getCard($addressBookId, $uri)) {
                $this->cardDavBackend->updateCard($addressBookId, $uri, $cardData);
            } else {
                $this->cardDavBackend->createCard($addressBookId, $uri, $cardData);
            }
        } catch (\Exception $e) {
            $this->logger->error("GoogleContactSync: 无法写入 vCard {$uri} - " . $e->getMessage());
        }
    }

    private function deleteContact($addressBookId, $resourceName) {
        $uri = str_replace('people/', '', $resourceName) . '.vcf';
        try {
            $this->cardDavBackend->deleteCard($addressBookId, $uri);
        } catch (\Exception $e) {}
    }

    private function getDefaultAddressBookId($userId) {
        $books = $this->cardDavBackend->getAddressBooksByOpaqueId($userId);
        foreach ($books as $book) {
            if (in_array($book['uri'], ['contacts', 'default'], true)) {
                return $book['id'];
            }
        }
        return !empty($books) ? $books[0]['id'] : null;
    }
}
