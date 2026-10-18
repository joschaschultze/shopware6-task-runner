<?php declare(strict_types=1);

namespace CronjobDe\TaskRunner\Controller;

use CronjobDe\TaskRunner\CronjobDeTaskRunner;
use CronjobDe\TaskRunner\Subscriber\AdminWorkerSubscriber;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\Scheduler\TaskScheduler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

/**
 * GET/POST /api/cronjob-de/run?token=...
 *
 * Does on every call what the admin worker does while someone has the administration open:
 * queue the due scheduled tasks, then consume the message queue - here until it is empty or the
 * time budget is used up. Meant to be called every few minutes by an external cron service.
 *
 * Answers in plain text so the call log of the cron service shows what happened:
 *   200 "OK ..."        everything handled (or nothing to do)
 *   200 "OK - busy"     a previous call is still working
 *   403                 token missing or wrong
 *   500 "ERROR ..."     an exception, or messages that failed for good
 */
class RunController
{
    /** Transports in the order the admin worker reads them; those missing in this version are skipped. */
    private const TRANSPORTS = ['webhook', 'async', 'low_priority', 'default'];

    /** Seconds of work per call when ?time= is not given; below the 30 s of a usual max_execution_time. */
    private const DEFAULT_BUDGET = 25;

    private const MAX_BUDGET = 55;

    /** @var TaskScheduler */
    private $taskScheduler;

    /** @var ContainerInterface */
    private $receiverLocator;

    /** @var MessageBusInterface */
    private $bus;

    /** @var LockFactory */
    private $lockFactory;

    /** @var SystemConfigService */
    private $systemConfig;

    /** @var LoggerInterface */
    private $logger;

    /** @var AdminWorkerSubscriber */
    private $adminWorker;

    /** @var EventSubscriberInterface[] retry, failure transport and queue statistics - whichever exist */
    private $subscribers;

    public function __construct(
        TaskScheduler $taskScheduler,
        ContainerInterface $receiverLocator,
        MessageBusInterface $bus,
        LockFactory $lockFactory,
        SystemConfigService $systemConfig,
        LoggerInterface $logger,
        AdminWorkerSubscriber $adminWorker,
        ?EventSubscriberInterface $retryListener = null,
        ?EventSubscriberInterface $failureListener = null,
        ?EventSubscriberInterface $statsSubscriber = null
    ) {
        $this->taskScheduler = $taskScheduler;
        $this->receiverLocator = $receiverLocator;
        $this->bus = $bus;
        $this->lockFactory = $lockFactory;
        $this->systemConfig = $systemConfig;
        $this->logger = $logger;
        $this->adminWorker = $adminWorker;
        $this->subscribers = array_values(array_filter([$retryListener, $failureListener, $statsSubscriber]));
    }

    public function run(Request $request): Response
    {
        $token = (string) $this->systemConfig->get(CronjobDeTaskRunner::CONFIG_TOKEN);
        $given = (string) ($request->headers->get('X-Cronjob-Token') ?? $request->query->get('token', ''));
        if ($token === '' || !hash_equals($token, $given)) {
            return $this->text("Access denied: token missing or wrong.\n", Response::HTTP_FORBIDDEN);
        }

        $budget = (int) $request->query->get('time', (string) self::DEFAULT_BUDGET);
        $budget = max(5, min(self::MAX_BUDGET, $budget));

        $lock = $this->lockFactory->createLock('cronjob_de_task_runner', $budget + 60);
        if (!$lock->acquire()) {
            $this->adminWorker->markRun();
            return $this->text("OK - busy: the previous call is still working.\n");
        }

        // Keep working if the caller hangs up; the lock stops a second run in the meantime.
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit($budget + 30);
        }

        $start = microtime(true);
        try {
            $this->taskScheduler->queueScheduledTasks();
            $count = $this->consume($budget - (microtime(true) - $start));
        } catch (\Throwable $e) {
            $this->logger->error('Cronjob.de task runner: ' . $e->getMessage(), ['exception' => $e]);
            return $this->text('ERROR: ' . get_class($e) . ': ' . $e->getMessage() . "\n", Response::HTTP_INTERNAL_SERVER_ERROR);
        } finally {
            $lock->release();
        }
        // The cron service works - this keeps the admin worker switched off (see AdminWorkerSubscriber).
        $this->adminWorker->markRun();

        $text = sprintf(
            "%s: scheduled tasks queued, %d messages handled, %d failed, %d to retry (%.1f s)\n",
            $count['failed'] > 0 ? 'ERROR' : 'OK',
            $count['handled'],
            $count['failed'],
            $count['retry'],
            microtime(true) - $start
        );
        return $this->text($text, $count['failed'] > 0 ? Response::HTTP_INTERNAL_SERVER_ERROR : Response::HTTP_OK);
    }

    /**
     * Consumes all known transports until they are empty or $seconds have passed.
     *
     * @return array{handled: int, failed: int, retry: int}
     */
    private function consume(float $seconds): array
    {
        $count = ['handled' => 0, 'failed' => 0, 'retry' => 0];

        $receivers = [];
        foreach (self::TRANSPORTS as $name) {
            if ($this->receiverLocator->has($name)) {
                $receivers[$name] = $this->receiverLocator->get($name);
            }
        }
        if ($receivers === [] || $seconds < 1) {
            return $count;
        }

        // A dispatcher of its own, like Shopware's ConsumeMessagesController, so the listeners
        // below only apply to this worker and not to the rest of the request.
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener(max(1, (int) floor($seconds))));
        $memoryLimit = $this->memoryLimitBytes();
        if ($memoryLimit > 0) {
            $dispatcher->addSubscriber(new StopWorkerOnMemoryLimitListener((int) ($memoryLimit * 0.8)));
        }
        foreach ($this->subscribers as $subscriber) {
            $dispatcher->addSubscriber($subscriber);
        }
        $dispatcher->addListener(WorkerMessageHandledEvent::class, function () use (&$count): void {
            ++$count['handled'];
        });
        // Runs after the retry listener (priority 100), which marks messages it sends for retry.
        $dispatcher->addListener(WorkerMessageFailedEvent::class, function (WorkerMessageFailedEvent $event) use (&$count): void {
            ++$count[$event->willRetry() ? 'retry' : 'failed'];
        }, -100);
        // Stop as soon as one round over all transports brought nothing.
        $dispatcher->addListener(WorkerRunningEvent::class, function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        });

        $worker = new Worker($receivers, $this->bus, $dispatcher);
        $worker->run(['sleep' => 100000]);

        return $count;
    }

    private function memoryLimitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return 0;
        }
        $value = (int) $limit;
        switch (strtolower(substr($limit, -1))) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }
        return $value;
    }

    private function text(string $text, int $status = Response::HTTP_OK): Response
    {
        $response = new Response($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        return $response;
    }
}
