<?php declare(strict_types=1);

use CronjobDe\TaskRunner\Controller\RunController;
use CronjobDe\TaskRunner\Subscriber\AdminWorkerSubscriber;
use CronjobDe\TaskRunner\Subscriber\ConfigSubscriber;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\Scheduler\TaskScheduler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// PHP format instead of services.xml: Shopware 6.7 warns about XML, 6.8 no longer loads it. Works from 6.5 on.
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(RunController::class)
        ->public()
        ->args([
            service(TaskScheduler::class),
            service('messenger.receiver_locator'),
            service('messenger.routable_message_bus'),
            service('lock.factory'),
            service(SystemConfigService::class),
            service('logger'),
            service(AdminWorkerSubscriber::class),
            // Optional: whichever of these exists in the installed Shopware/Symfony version is used.
            service('messenger.retry.send_failed_message_for_retry_listener')->nullOnInvalid(),
            service('messenger.failure.send_failed_message_to_failure_transport_listener')->nullOnInvalid(),
            service('Shopware\Core\Framework\MessageQueue\Subscriber\MessageQueueStatsSubscriber')->nullOnInvalid(),
        ]);

    $services->set(AdminWorkerSubscriber::class)
        ->args([
            service(SystemConfigService::class),
            service('cache.object'),
        ])
        ->tag('kernel.event_subscriber');

    $services->set(ConfigSubscriber::class)
        ->args([
            service(SystemConfigService::class),
        ])
        ->tag('kernel.event_subscriber');
};
