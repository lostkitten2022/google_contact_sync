<?php
namespace OCA\GoogleContactSync\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\Dav\CardDAV\CardDavBackend;
use OCA\GoogleContactSync\Service\ContactSyncService;

class Application extends App implements IBootstrap {
    public const APP_ID = 'google_contact_sync';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // 跨模块显式注入 CardDavBackend
        $context->registerService(CardDavBackend::class, function ($c) {
            $server = $c->getServer();
            return new CardDavBackend(
                $server->getDatabaseConnection(),
                $server->getConfig()
            );
        });

        // 注册通讯录核心同步逻辑
        $context->registerService(ContactSyncService::class, function ($c) {
            return new ContactSyncService(
                $c->get(\OCA\IntegrationGoogle\Service\TokenProvider::class),
                $c->get(\OCP\IConfig::class),
                $c->get(\OCP\ILogger::class),
                $c->get(CardDavBackend::class)
            );
        });
    }

    public function boot(IBootContext $context): void {}
}
