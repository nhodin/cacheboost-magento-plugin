<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Service;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Model\Queue\ProcessingStrategy;
use CacheBoost\Warmer\Model\Queue\WarmConsumer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Collects cache tags during the request and publishes them as a single queue
 * message at flush time. Acts as a per-request buffer: multiple flush events
 * are batched into one message. Shared as a singleton by the DI container.
 *
 * Nothing expensive happens on the request path: publishing is one INSERT into
 * the MySQL-backed queue. Tag→URL resolution and the CacheBoost API calls run
 * asynchronously in the cacheboost.warm consumer (Model\Queue\WarmConsumer),
 * so the customer/admin request that invalidated the cache never waits on them.
 *
 * In "Synchronous" processing mode (installations without cron) the message is
 * handed to the consumer directly instead: inline in CLI, and from a shutdown
 * function after the HTTP response has been sent on PHP-FPM / LiteSpeed.
 */
class UrlCollector
{
    /** Queue topic consumed by Model\Queue\WarmConsumer (see etc/communication.xml). */
    public const TOPIC = 'cacheboost.warm';

    /**
     * Maximum number of tags carried in a single message. Bounds both the
     * message size and the consumer's DB work (one UrlFinder query per tag per
     * store). Above the cap the message is flagged truncated and the consumer
     * falls back to a full Boost run when one is configured.
     */
    private const MAX_TAGS = 500;

    /** @var array<string, true> */
    private array $pendingTags = [];
    private bool $fullFlushPending = false;
    private bool $flushed = false;
    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly Config $config,
        private readonly PublisherInterface $publisher,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly ProcessingStrategy $strategy,
        private readonly WarmConsumer $consumer
    ) {}

    public function markFullFlush(): void
    {
        $this->fullFlushPending = true;
        $this->registerShutdownFallback();
    }

    public function collectTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $this->pendingTags[$tag] = true;
            }
        }
        if (!empty($this->pendingTags)) {
            $this->registerShutdownFallback();
        }
    }

    /**
     * controller_front_send_response_before never fires in CLI/cron contexts
     * (bin/magento indexer:reindex, cron jobs…), so a shutdown function is the
     * only guaranteed flush point there. In HTTP requests the event observer
     * flushes first and this becomes a no-op thanks to the $flushed guard.
     */
    private function registerShutdownFallback(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function([$this, 'flush']);
    }

    /**
     * Called once at the end of the request (controller_front_send_response_before).
     * Publishes a single queue message regardless of how many flush events fired.
     */
    public function flush(): void
    {
        if ($this->flushed || !$this->config->isConfigured()) {
            return;
        }
        $this->flushed = true;

        // Full flush takes priority over any buffered tags.
        if ($this->fullFlushPending) {
            $this->dispatch(['full_flush' => true, 'tags' => [], 'truncated' => false]);
            return;
        }

        if (empty($this->pendingTags)) {
            return;
        }

        $tags = array_keys($this->pendingTags);
        $truncated = false;
        if (count($tags) > self::MAX_TAGS) {
            $this->logger->info(sprintf(
                'CacheBoost: %d invalidated tags exceed the limit of %d — message truncated, ' .
                'the consumer will fall back to a full Boost run if one is configured.',
                count($tags),
                self::MAX_TAGS
            ));
            $tags = array_slice($tags, 0, self::MAX_TAGS);
            $truncated = true;
        }

        $this->dispatch(['full_flush' => false, 'tags' => $tags, 'truncated' => $truncated]);
    }

    private function dispatch(array $payload): void
    {
        try {
            $sync = $this->strategy->resolve() === ProcessingStrategy::SYNC;
        } catch (\Throwable $e) {
            $this->logger->warning('CacheBoost: unable to resolve the processing strategy — ' . $e->getMessage());
            $sync = false;
        }

        $sync ? $this->processSynchronously($payload) : $this->publish($payload);
    }

    /**
     * Synchronous mode. In HTTP the work is deferred to a shutdown function that
     * first closes the connection to the client, so the visitor still does not
     * wait; the PHP worker stays busy for the duration of the API call instead.
     * Without fastcgi_finish_request() (mod_php), the response is delayed by up
     * to the API timeout — use a cron-based mode there whenever possible.
     */
    private function processSynchronously(array $payload): void
    {
        try {
            $message = $this->json->serialize($payload);
        } catch (\Throwable $e) {
            $this->logger->error('CacheBoost: failed to encode warm request — ' . $e->getMessage());
            return;
        }

        if (PHP_SAPI === 'cli') {
            // CLI flushes already run at shutdown (see registerShutdownFallback()).
            $this->consumer->process($message);
            return;
        }

        register_shutdown_function(function () use ($message): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $this->consumer->process($message);
        });
    }

    /** Publishing must never break the request that triggered the invalidation. */
    private function publish(array $payload): void
    {
        try {
            $this->publisher->publish(self::TOPIC, $this->json->serialize($payload));
            $this->logger->info(sprintf(
                'CacheBoost: queued warm request (%s, %d tag(s)).',
                $payload['full_flush'] ? 'full flush' : 'tags',
                count($payload['tags'])
            ));
        } catch (\Throwable $e) {
            $this->logger->error('CacheBoost: failed to queue warm request — ' . $e->getMessage());
        }
    }
}
