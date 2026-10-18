<?php declare(strict_types=1);

namespace CronjobDe\TaskRunner\Subscriber;

use CronjobDe\TaskRunner\CronjobDeTaskRunner;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Keeps the displayed address and connect link in step with the token when someone changes it. */
class ConfigSubscriber implements EventSubscriberInterface
{
    /** @var SystemConfigService */
    private $systemConfig;

    public function __construct(SystemConfigService $systemConfig)
    {
        $this->systemConfig = $systemConfig;
    }

    public static function getSubscribedEvents(): array
    {
        return [SystemConfigChangedEvent::class => 'onChanged'];
    }

    public function onChanged(SystemConfigChangedEvent $event): void
    {
        if ($event->getKey() !== CronjobDeTaskRunner::CONFIG_TOKEN || $event->getSalesChannelId() !== null) {
            return;
        }
        $token = (string) $event->getValue();
        if ($token === '') {
            // An emptied field would lock the cron service out for good - create a new token instead.
            $token = bin2hex(random_bytes(16));
            $this->systemConfig->set(CronjobDeTaskRunner::CONFIG_TOKEN, $token);
            return; // the set() above triggers this subscriber again with the new token
        }
        CronjobDeTaskRunner::writeAddresses($this->systemConfig, $token);
    }
}
