<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Test\Unit\Support;

use Magento\Framework\FlagManager;

/** In-memory flag table; counts reads so tests can prove a path never hits the database. */
final class MemoryFlags extends FlagManager
{
    /** @var array<string, mixed> */
    public array $flags = [];
    public int $reads = 0;

    public function getFlagData($flagCode)
    {
        $this->reads++;
        return $this->flags[$flagCode] ?? null;
    }

    public function saveFlag($flagCode, $value)
    {
        $this->flags[$flagCode] = $value;
        return true;
    }

    public function deleteFlag($flagCode)
    {
        unset($this->flags[$flagCode]);
        return true;
    }
}
