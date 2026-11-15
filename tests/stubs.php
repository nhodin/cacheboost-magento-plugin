<?php

// Minimal stubs for Magento interfaces and classes used by the plugin.
// These replace the full magento/framework dependency for standalone unit tests.

namespace Magento\Framework\App\Config;

interface ReinitableConfigInterface
{
    public function reinit();
}

interface ScopeConfigInterface
{
    const SCOPE_TYPE_DEFAULT = 'default';
    public function getValue($path, $scopeType = 'default', $scopeCode = null);
    public function isSetFlag($path, $scopeType = 'default', $scopeCode = null): bool;
}

namespace Magento\Framework\Encryption;

interface EncryptorInterface
{
    public function decrypt(string $data): string;
    public function encrypt(string $data): string;
}

namespace Magento\Framework\HTTP\Client;

class Curl
{
    public function setTimeout(int $timeout): void {}
    public function addHeader(string $name, string $value): void {}
    public function get(string $url): void {}
    public function post(string $url, $params): void {}
    public function getStatus(): int { return 200; }
    public function getBody(): string { return ''; }
    public function setOption($name, $value): void {}
}

class CurlFactory
{
    public function create(array $data = []): Curl { return new Curl(); }
}

namespace Magento\Framework\MessageQueue;

interface PublisherInterface
{
    public function publish($topicName, $data);
}

interface EnvelopeInterface
{
    public function getBody();
    public function getProperties();
}

interface QueueInterface
{
    public function dequeue();
    public function acknowledge(EnvelopeInterface $envelope);
    public function subscribe($callback);
    public function reject(EnvelopeInterface $envelope, $requeue = true, $rejectionMessage = null);
    public function push(EnvelopeInterface $envelope);
}

interface QueueFactoryInterface
{
    public function create($queueName, $connectionName);
}

class MessageEncoder
{
    public function decode($topic, $message, $requestType = true)
    {
        return json_decode((string) $message, true);
    }
}

namespace Magento\Framework\MessageQueue\Consumer\Config;

interface ConsumerConfigItemInterface
{
    public function getName();
    public function getQueue();
    public function getConnection();
}

namespace Magento\Framework\MessageQueue\Consumer;

interface ConfigInterface
{
    public function getConsumer($name);
    public function getConsumers();
}

namespace Magento\Framework\App;

class DeploymentConfig
{
    public function get($key = null, $defaultValue = null) { return $defaultValue; }
}

class ResourceConnection
{
    public function getConnection($resourceName = 'default') { return null; }
    public function getTableName($modelEntity, $connectionName = 'default') { return (string) $modelEntity; }
}

namespace Magento\Framework;

class FlagManager
{
    public function getFlagData($flagCode) { return null; }
    public function saveFlag($flagCode, $value) { return true; }
    public function deleteFlag($flagCode) { return true; }
}

namespace Magento\Framework\Serialize\Serializer;

class Json
{
    public function serialize($data): string
    {
        $result = json_encode($data);
        if ($result === false) {
            throw new \InvalidArgumentException('Unable to serialize value.');
        }
        return $result;
    }

    public function unserialize($string)
    {
        $result = json_decode((string) $string, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Unable to unserialize value.');
        }
        return $result;
    }
}

namespace Magento\Framework\App;

interface CacheInterface
{
    public function load($identifier);
    public function save($data, $identifier, $tags = [], $lifeTime = null);
    public function remove($identifier);
    public function clean($tags = []);
}

namespace Magento\Framework\Event;

interface ObserverInterface
{
    public function execute(Observer $observer): void;
}

class Observer {}

namespace Magento\Store\Api\Data;

interface StoreInterface
{
    public function isActive(): bool;
    public function getBaseUrl(): string;
    public function getId(): mixed;
    public function getCode();
}

namespace Magento\Store\Model;

interface ScopeInterface
{
    const SCOPE_STORE = 'store';
    const SCOPE_STORES = 'stores';
    const SCOPE_WEBSITE = 'website';
}

interface StoreManagerInterface
{
    public function getStores(bool $withDefault = false, bool $codeKey = false): array;
    public function getDefaultStoreView(): ?\Magento\Store\Api\Data\StoreInterface;
    public function getWebsites($withDefault = false, $codeKey = false);
}

namespace Magento\UrlRewrite\Model;

interface UrlFinderInterface
{
    public function findAllByData(array $data): array;
}

namespace Magento\UrlRewrite\Service\V1\Data;

class UrlRewrite
{
    const ENTITY_TYPE   = 'entity_type';
    const ENTITY_ID     = 'entity_id';
    const STORE_ID      = 'store_id';
    const REDIRECT_TYPE = 'redirect_type';

    public function getRequestPath(): string { return ''; }
}

namespace Magento\Framework\App\Config\Storage;

interface WriterInterface
{
    public function save($path, $value, $scope = 'default', $scopeId = 0);
    public function delete($path, $scope = 'default', $scopeId = 0);
}

namespace Magento\Framework;

interface UrlInterface
{
    const URL_TYPE_LINK = 'link';
    const URL_TYPE_WEB = 'web';
}

namespace Magento\Framework\App\Cache;

interface StateInterface
{
    public function isEnabled($cacheType);
}

interface TypeListInterface
{
    public function getTypes();
    public function getInvalidated();
    public function cleanType($typeCode);
}

namespace Magento\Framework\App;

interface ProductMetadataInterface
{
    public function getVersion();
    public function getEdition();
    public function getName();
}

class State
{
    public function getMode() { return 'production'; }
}

class MaintenanceMode
{
    public function isOn($remoteAddr = '') { return false; }
}

namespace Magento\Framework\Module;

interface ModuleListInterface
{
    public function getAll();
    public function getOne($name);
    public function getNames();
    public function has($name);
}

class FullModuleList
{
    public function has($name) { return false; }
}

class PackageInfo
{
    public function getVersion($moduleName) { return ''; }
}


namespace Magento\Framework;

class DataObject
{
    public function __construct(private array $data = []) {}
    public function getData($key = '') { return $key === '' ? $this->data : ($this->data[$key] ?? null); }
}

namespace Magento\Framework\Data;

interface OptionSourceInterface
{
    public function toOptionArray();
}
