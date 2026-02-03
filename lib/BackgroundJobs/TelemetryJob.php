<?php
declare(strict_types=1);

namespace OCA\MetaVox\BackgroundJobs;

use OCA\MetaVox\Service\TelemetryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that sends anonymous telemetry data daily
 * This job runs once per day and only sends data if telemetry is enabled
 */
class TelemetryJob extends TimedJob {
    private TelemetryService $telemetryService;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        TelemetryService $telemetryService,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->telemetryService = $telemetryService;
        $this->logger = $logger;

        // Run once per day (24 hours = 86400 seconds)
        $this->setInterval(86400);
    }

    protected function run($argument): void {
        if (!$this->telemetryService->isEnabled()) {
            $this->logger->debug('TelemetryJob: Telemetry is disabled, skipping');
            return;
        }

        if (!$this->telemetryService->shouldSendReport()) {
            $this->logger->debug('TelemetryJob: Report was sent recently, skipping');
            return;
        }

        $this->logger->info('TelemetryJob: Sending telemetry report');
        $success = $this->telemetryService->sendReport();

        if ($success) {
            $this->logger->info('TelemetryJob: Report sent successfully');
        } else {
            $this->logger->warning('TelemetryJob: Failed to send report');
        }
    }
}
