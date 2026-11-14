<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Test\Unit\Support;

use Magento\Framework\App\CacheInterface;

/** In-memory Magento cache: records lifetimes so tests can check them. */
final class MemoryCache implements CacheInterface
{
    /** @var array<string, string> */
    public array $data = [];
    /** @var array<string, int|null> */
    public array $lifetimes = [];

    public function load($identifier)
    {
        return $this->data[$identifier] ?? false;
    }

    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        $this->data[$identifier] = (string) $data;
        $this->lifetimes[$identifier] = $lifeTime;
        return true;
    }

    public function remove($identifier)
    {
        unset($this->data[$identifier]);
        return true;
    }

    public function clean($tags = [])
    {
        $this->data = [];
        return true;
    }
}
