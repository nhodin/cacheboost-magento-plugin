<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Test\Unit\Model\Account;

use CacheBoost\CacheWarmer\Model\Account\AccountStatus;
use CacheBoost\CacheWarmer\Model\Config;
use CacheBoost\CacheWarmer\Service\AccountApi;
use CacheBoost\CacheWarmer\Test\Unit\Support\MemoryCache;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class AccountStatusTest extends TestCase
{
    public function testStatesFromTheApi(): void
    {
        self::assertSame(['state' => 'blocked'], AccountStatus::fromResponse(403, ['code' => 'email_unverified']));
        self::assertSame(['state' => 'unknown'], AccountStatus::fromResponse(403, ['code' => 'insufficient_scope']));
        self::assertSame(['state' => 'unknown'], AccountStatus::fromResponse(0, null));
        self::assertSame(
            ['state' => 'pending', 'deadline' => '2026-11-21 10:00:00'],
            AccountStatus::fromResponse(200, ['email_verified' => false, 'email_verify_deadline' => '2026-11-21 10:00:00'])
        );
        self::assertSame(['state' => 'ok'], AccountStatus::fromResponse(200, ['email_verified' => true]));
        // Older API versions do not send the fields.
        self::assertSame(['state' => 'ok'], AccountStatus::fromResponse(200, ['scopes' => []]));
    }

    public function testStateIsCachedWithTheWordpressLifetimes(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn('cb_live_k');
        $api = $this->createMock(AccountApi::class);
        $api->expects(self::once())->method('me')->with('cb_live_k', 3)
            ->willReturn(['status' => 200, 'body' => ['email_verified' => false, 'email_verify_deadline' => '2026-11-21']]);
        $cache = new MemoryCache();
        $status = new AccountStatus($config, $api, $cache, new Json());

        self::assertSame('pending', $status->get()['state']);
        self::assertSame('pending', $status->get()['state'], 'second read comes from the cache');
        self::assertSame([3600], array_values($cache->lifetimes));

        $status->clear();
        self::assertSame([], $cache->data);
    }

    public function testNoKeyMeansNoApiCall(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn('');
        $api = $this->createMock(AccountApi::class);
        $api->expects(self::never())->method('me');

        self::assertSame(['state' => 'unknown'], (new AccountStatus($config, $api, new MemoryCache(), new Json()))->get());
    }
}
