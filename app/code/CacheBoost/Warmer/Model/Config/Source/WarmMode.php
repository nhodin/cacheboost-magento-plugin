<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class WarmMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'smart',     'label' => __('Smart — targeted tag-based warming (recommended)')],
            ['value' => 'full_only', 'label' => __('Full Only — always trigger the full scheduled Boost')],
        ];
    }
}
