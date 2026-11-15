<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Model\Config\Source;

use CacheBoost\CacheWarmer\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class ProcessingMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::PROCESSING_AUTO,  'label' => __('Auto — queue consumer when Magento runs it, module cron job otherwise (recommended)')],
            ['value' => Config::PROCESSING_QUEUE, 'label' => __('Queue consumer only — consumer started by cron or a process supervisor')],
            ['value' => Config::PROCESSING_CRON,  'label' => __('Cron job — the module cron processes the queue every minute')],
            ['value' => Config::PROCESSING_SYNC,  'label' => __('Synchronous — at the end of the request, no cron required')],
        ];
    }
}
