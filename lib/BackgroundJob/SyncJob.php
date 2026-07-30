<?php
namespace OCA\GoogleContactSync\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\GoogleContactSync\Service\ContactSyncService;

class SyncJob extends TimedJob {
    private $syncService;

    public function __construct(ITimeFactory $time, ContactSyncService $syncService) {
        parent::__construct($time);
        $this->syncService = $syncService;
        $this->setInterval(3600); // 1 小时检测一次
    }

    protected function run($argument): void {
        $this->syncService->syncAllUsers();
    }
}
