<?php
namespace OCA\GoogleContactSync\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class SyncController extends Controller {
    private $config;
    private $userId;

    public function __construct($AppName, IRequest $request, IConfig $config, $userId) {
        parent::__construct($AppName, $request);
        $this->config = $config;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getStatus() {
        $enabled = $this->config->getUserValue($this->userId, 'google_contact_sync', 'enabled', 'no');
        return new DataResponse(['enabled' => $enabled === 'yes']);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function setStatus($enabled) {
        $val = ($enabled === 'true' || $enabled === true) ? 'yes' : 'no';
        $this->config->setUserValue($this->userId, 'google_contact_sync', 'enabled', $val);
        return new DataResponse(['status' => 'success', 'enabled' => $val]);
    }
}
