<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Test\Unit\Service;

use CacheBoost\CacheWarmer\Model\Config;
use CacheBoost\CacheWarmer\Service\AccountApi;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AccountApiTest extends TestCase
{
    /** @var list<array{string, mixed}> */
    private array $calls = [];
    private int $status = 200;
    private string $body = '{"ok":true}';

    private function api(): AccountApi
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('addHeader')->willReturnCallback(function ($n, $v) { $this->calls[] = ['header', "$n: $v"]; });
        $curl->method('setTimeout')->willReturnCallback(function ($t) { $this->calls[] = ['timeout', $t]; });
        $curl->method('setOption')->willReturnCallback(function ($o, $v) { $this->calls[] = ['option', [$o, $v]]; });
        $curl->method('get')->willReturnCallback(function ($u) { $this->calls[] = ['get', $u]; });
        $curl->method('post')->willReturnCallback(function ($u, $b) { $this->calls[] = ['post', [$u, $b]]; });
        $curl->method('getStatus')->willReturnCallback(fn () => $this->status);
        $curl->method('getBody')->willReturnCallback(fn () => $this->body);
        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturn($curl);

        $config = $this->createMock(Config::class);
        $config->method('getApiEndpoint')->willReturn('https://api.cache-boost.com');
        return new AccountApi($config, $factory, $this->createMock(LoggerInterface::class));
    }

    private function calls(string $type): array
    {
        return array_column(array_filter($this->calls, fn ($c) => $c[0] === $type), 1);
    }

    public function testGetSendsTheGivenKey(): void
    {
        self::assertSame(['status' => 200, 'body' => ['ok' => true]], $this->api()->me('cb_live_k'));
        self::assertSame(['https://api.cache-boost.com/v1/me'], $this->calls('get'));
        self::assertContains('Authorization: Bearer cb_live_k', $this->calls('header'));
    }

    public function testConnectTokenExchangeSendsNoKey(): void
    {
        $this->api()->exchangeConnectCode('code', 'verifier');
        self::assertSame([['https://api.cache-boost.com/v1/connect/token', '{"code":"code","code_verifier":"verifier"}']], $this->calls('post'));
        self::assertEmpty(array_filter($this->calls('header'), fn ($h) => str_starts_with($h, 'Authorization')));
    }

    public function testDeleteUsesTheCustomVerb(): void
    {
        $this->api()->deleteEnvironment('cb_live_k', 42);
        self::assertSame([[CURLOPT_CUSTOMREQUEST, 'DELETE']], $this->calls('option'));
        self::assertSame('https://api.cache-boost.com/v1/sites/42/environment', $this->calls('post')[0][0]);
    }

    public function testValidationGetsALongTimeout(): void
    {
        $this->api()->validateSite('cb_live_k', 42);
        self::assertSame([AccountApi::VALIDATE_TIMEOUT], $this->calls('timeout'));
    }

    public function testErrorsAndNetworkFailuresNeverThrow(): void
    {
        $this->status = 422;
        $this->body = 'not json';
        self::assertSame(['status' => 422, 'body' => null], $this->api()->sites('cb_live_k'));

        $curl = $this->createMock(Curl::class);
        $curl->method('get')->willThrowException(new \RuntimeException('Could not resolve host'));
        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturn($curl);
        $api = new AccountApi($this->createMock(Config::class), $factory, $this->createMock(LoggerInterface::class));
        self::assertSame(['status' => 0, 'body' => null], $api->me('cb_live_k'));
    }
}
