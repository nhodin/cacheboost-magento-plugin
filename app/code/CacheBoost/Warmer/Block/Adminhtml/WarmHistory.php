<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Block\Adminhtml;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Service\ApiClient;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * frontend_model for the "Warm History" field in system.xml.
 * Calls GET /v1/sites/{id}/warm-runs and renders a styled table (see warm_history.phtml).
 */
class WarmHistory extends Field
{
    /** Runs are cached briefly so reloading the config page doesn't block on two HTTP calls. */
    private const CACHE_TTL = 60;

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        private readonly CacheInterface $cache,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setTemplate('CacheBoost_Warmer::system/config/warm_history.phtml');
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function render(AbstractElement $element): string
    {
        return '<tr><td colspan="4" style="padding:10px 0">' . $this->toHtml() . '</td></tr>';
    }

    /**
     * Whether the API Key and Site ID are configured (history can be fetched).
     */
    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Normalized warm runs ready for presentation. Any load failure yields an
     * empty list: the history panel must never break the whole configuration page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRuns(): array
    {
        if (!$this->config->isConfigured()) {
            return [];
        }

        try {
            $runs = $this->loadRuns();
        } catch (\Throwable) {
            return [];
        }

        return array_map($this->normalizeRun(...), $runs);
    }

    private function loadRuns(): array
    {
        $boostId  = $this->config->getBoostId();
        $cacheKey = sprintf('cacheboost_warm_history_%d_%d', $this->config->getSiteId(), $boostId);

        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            $runs = json_decode($cached, true);
            if (is_array($runs)) {
                return $runs;
            }
        }

        $inlineRuns = $this->apiClient->getWarmRuns(10);
        foreach ($inlineRuns as &$r) {
            $r['_type'] = 'inline';
        }
        unset($r);

        $boostRuns = [];
        if ($boostId > 0) {
            $boostRuns = $this->apiClient->getBoostRuns($boostId, 10);
            foreach ($boostRuns as &$r) {
                $r['_type'] = 'full';
            }
            unset($r);
        }

        $runs = array_merge($inlineRuns, $boostRuns);

        // Sort by created_at descending, keep the 15 most recent.
        usort($runs, static fn($a, $b) => strcmp(
            $b['created_at'] ?? '',
            $a['created_at'] ?? ''
        ));
        $runs = array_slice($runs, 0, 15);

        $this->cache->save(json_encode($runs), $cacheKey, [], self::CACHE_TTL);

        return $runs;
    }

    /**
     * Flatten one raw API run into the scalar fields the template displays.
     *
     * @return array<string, mixed>
     */
    private function normalizeRun(array $run): array
    {
        $id = (int) ($run['id'] ?? 0);

        $date = '—';
        if (isset($run['created_at'])) {
            try {
                $date = (new \DateTimeImmutable((string) $run['created_at']))->format('d/m/Y H:i');
            } catch (\Throwable) {
                // Malformed API date: keep the placeholder rather than break the page.
            }
        }

        $summary = $run['summary'] ?? [];
        $hit     = isset($summary['hit'])  ? (int) $summary['hit']  : null;
        $miss    = isset($summary['miss']) ? (int) $summary['miss'] : null;
        $hitRate = ($hit !== null && $miss !== null && ($hit + $miss) > 0)
            ? round($hit / ($hit + $miss) * 100) . '%'
            : '—';

        return [
            'id'          => $id,
            'app_run_url' => 'https://app.cache-boost.com/boosts/run/' . $id,
            'status'      => (string) ($run['status'] ?? ''),
            'type'        => ($run['_type'] ?? '') === 'full' ? 'full' : 'inline',
            'date'        => $date,
            'url_count'   => isset($run['source_urls']) && is_array($run['source_urls'])
                ? (string) count($run['source_urls'])
                : '—',
            'region'      => isset($run['run_region']) && is_array($run['run_region'])
                ? implode(', ', $run['run_region'])
                : '—',
            'hit'         => $hit,
            'miss'        => $miss,
            'hit_rate'    => $hitRate,
        ];
    }
}
