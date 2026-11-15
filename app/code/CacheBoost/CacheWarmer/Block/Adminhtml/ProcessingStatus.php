<?php

declare(strict_types=1);

namespace CacheBoost\CacheWarmer\Block\Adminhtml;

use CacheBoost\CacheWarmer\Model\Queue\ProcessingDiagnostics;
use CacheBoost\CacheWarmer\Model\Queue\ProcessingStrategy;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * frontend_model for the status row of the "Background Processing" group.
 */
class ProcessingStatus extends Field
{
    public function __construct(
        Context $context,
        private readonly ProcessingDiagnostics $diagnostics,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setTemplate('CacheBoost_CacheWarmer::system/config/processing_status.phtml');
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function render(AbstractElement $element): string
    {
        return '<tr><td colspan="4" style="padding:12px 0">' . $this->toHtml() . '</td></tr>';
    }

    public function getDiagnostics(): array
    {
        return $this->diagnostics->collect();
    }

    public function getStrategyLabel(string $strategy): string
    {
        return (string) match ($strategy) {
            ProcessingStrategy::CONSUMER => __('Queue consumer (cacheboostWarmConsumer)'),
            ProcessingStrategy::CRON     => __('Module cron job (every minute)'),
            ProcessingStrategy::SYNC     => __('Synchronous, at the end of the request'),
            default => $strategy,
        };
    }

    /** "3 min ago"-style rendering of an age in seconds. */
    public function formatAge(?int $seconds): string
    {
        if ($seconds === null) {
            return (string) __('never');
        }
        if ($seconds < 120) {
            return (string) __('%1 s ago', $seconds);
        }
        if ($seconds < 7200) {
            return (string) __('%1 min ago', (int) floor($seconds / 60));
        }
        return (string) __('%1 h ago', (int) floor($seconds / 3600));
    }
}
