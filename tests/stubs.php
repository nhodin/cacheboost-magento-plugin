<?php

declare(strict_types=1);

// Minimal stubs for Magento interfaces and classes used by the plugin.
// These replace the full magento/framework dependency for standalone unit tests.

namespace Magento\Framework\App\Config {
    interface ScopeConfigInterface
    {
        const SCOPE_TYPE_DEFAULT = 'default';
        public function getValue($path, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null);
        public function isSetFlag($path, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null): bool;
    }
}

namespace Magento\Framework\Encryption {
    interface EncryptorInterface
    {
        public function decrypt(string $data): string;
        public function encrypt(string $data): string;
    }
}

namespace Magento\Framework\HTTP\Client {
    class Curl
    {
        public function setTimeout(int $timeout): void {}
        public function addHeader(string $name, string $value): void {}
        public function get(string $url): void {}
        public function post(string $url, $params): void {}
        public function getStatus(): int { return 200; }
        public function getBody(): string { return ''; }
    }
}

namespace Magento\Framework\Event {
    interface ObserverInterface
    {
        public function execute(Observer $observer): void;
    }

    class Observer {}
}

namespace Magento\Store\Api\Data {
    interface StoreInterface
    {
        public function isActive(): bool;
        public function getBaseUrl(): string;
        public function getId(): mixed;
    }
}

namespace Magento\Store\Model {
    interface StoreManagerInterface
    {
        public function getStores(bool $withDefault = false, bool $codeKey = false): array;
    }
}

namespace Magento\UrlRewrite\Model {
    interface UrlFinderInterface
    {
        public function findAllByData(array $data): array;
    }
}

namespace Magento\UrlRewrite\Service\V1\Data {
    class UrlRewrite
    {
        const ENTITY_TYPE   = 'entity_type';
        const ENTITY_ID     = 'entity_id';
        const STORE_ID      = 'store_id';
        const REDIRECT_TYPE = 'redirect_type';

        public function getRequestPath(): string { return ''; }
    }
}
