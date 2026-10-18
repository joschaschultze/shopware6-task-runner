<?php declare(strict_types=1);

namespace CronjobDe\TaskRunner\Subscriber;

use CronjobDe\TaskRunner\CronjobDeTaskRunner;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Switches the admin worker off - but only while the cron service actually calls.
 *
 * The administration asks /api/_info/config whether to run its worker (adminWorker.enableAdminWorker).
 * While the last successful call of /api/cronjob-de/run is recent, this answer says "no". If the calls
 * stop (cronjob deleted, account expired, shop moved), the worker comes back on by itself after
 * STALE_AFTER seconds, so scheduled tasks never stop for good. shopware.yaml stays untouched.
 */
class AdminWorkerSubscriber implements EventSubscriberInterface
{
    public const CACHE_KEY = 'cronjob_de_task_runner_last_run';

    /** Three missed calls at the usual 5-minute interval. */
    public const STALE_AFTER = 900;

    /** @var SystemConfigService */
    private $systemConfig;

    /** @var CacheItemPoolInterface */
    private $cache;

    public function __construct(SystemConfigService $systemConfig, CacheItemPoolInterface $cache)
    {
        $this->systemConfig = $systemConfig;
        $this->cache = $cache;
    }

    public static function getSubscribedEvents(): array
    {
        // Before Symfony's ResponseListener (priority 0) prepares the response.
        return [KernelEvents::RESPONSE => ['onResponse', 10]];
    }

    /** Called by the run endpoint after every successful call. */
    public function markRun(): void
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set(time());
        $item->expiresAfter(86400);
        $this->cache->save($item);
    }

    /** Seconds since the last successful call, or null if there was none (or the cache was cleared). */
    public function secondsSinceLastRun(): ?int
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        return $item->isHit() ? max(0, time() - (int) $item->get()) : null;
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->getRequest()->attributes->get('_route') !== 'api.info.config') {
            return;
        }
        if (!$this->systemConfig->getBool(CronjobDeTaskRunner::CONFIG_WORKER_OFF)) {
            return;
        }
        $since = $this->secondsSinceLastRun();
        if ($since === null || $since > self::STALE_AFTER) {
            return;
        }

        $response = $event->getResponse();
        // Objects, not arrays: an empty {} (e.g. "bundles") must stay an object for the administration.
        $data = json_decode((string) $response->getContent());
        if (!is_object($data) || !isset($data->adminWorker) || !is_object($data->adminWorker)) {
            return;
        }
        $data->adminWorker->enableAdminWorker = false;
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json !== false) {
            $response->setContent($json);
        }
    }
}
