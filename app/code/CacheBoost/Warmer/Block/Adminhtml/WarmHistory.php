<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Block\Adminhtml;

use CacheBoost\Warmer\Model\Config;
use CacheBoost\Warmer\Service\ApiClient;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * frontend_model for the "Historique des préchauffages" field in system.xml.
 * Calls GET /v1/sites/{id}/warm-runs and renders a styled table.
 */
class WarmHistory extends Field
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ApiClient $apiClient,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function render(AbstractElement $element): string
    {
        if (!$this->config->isConfigured()) {
            return $this->wrapRow($this->notice(
                (string) __('Configure the API Key and Site ID to display warm history.')
            ));
        }

        $inlineRuns = $this->apiClient->getWarmRuns(10);
        foreach ($inlineRuns as &$r) {
            $r['_type'] = 'inline';
        }
        unset($r);

        $boostRuns = [];
        $boostId   = $this->config->getBoostId();
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

        if (empty($runs)) {
            return $this->wrapRow($this->notice((string) __('No warm runs found for this site.')));
        }

        return $this->wrapRow($this->table($runs));
    }

    private function table(array $runs): string
    {
        $rows = '';
        foreach ($runs as $run) {
            $id     = (int) ($run['id'] ?? 0);
            $status = htmlspecialchars((string) ($run['status'] ?? ''), ENT_QUOTES);
            $type   = $run['_type'] === 'full' ? 'full' : 'inline';
            $date   = isset($run['created_at'])
                ? (new \DateTimeImmutable($run['created_at']))->format('d/m/Y H:i')
                : '—';
            $urlCount = is_array($run['source_urls']) ? count($run['source_urls']) : '—';
            $region   = is_array($run['run_region'])
                ? htmlspecialchars(implode(', ', $run['run_region']), ENT_QUOTES)
                : '—';

            $summary  = $run['summary'] ?? [];
            $hit      = isset($summary['hit'])  ? (int) $summary['hit']  : null;
            $miss     = isset($summary['miss']) ? (int) $summary['miss'] : null;
            $hitRate  = ($hit !== null && $miss !== null && ($hit + $miss) > 0)
                ? round($hit / ($hit + $miss) * 100) . '%'
                : '—';

            $appRunUrl = 'https://app.cache-boost.com/boosts/run/' . $id;
            $rows .= sprintf(
                '<tr style="border-bottom:1px solid #eee">
                    <td style="padding:7px 12px;font-size:12px">
                        <a href="%s" target="_blank" rel="noopener"
                           style="color:#1565c0;text-decoration:none;font-weight:600">#%d ↗</a>
                    </td>
                    <td style="padding:7px 12px">%s</td>
                    <td style="padding:7px 12px">%s</td>
                    <td style="padding:7px 12px;color:#555">%s</td>
                    <td style="padding:7px 12px;text-align:right">%s</td>
                    <td style="padding:7px 12px;color:#555">%s</td>
                    <td style="padding:7px 12px">%s</td>
                </tr>',
                htmlspecialchars($appRunUrl, ENT_QUOTES),
                $id,
                $this->statusBadge($status),
                $this->typeBadge($type),
                $date,
                $urlCount,
                $region,
                $this->hitRateCell($hit, $miss, $hitRate)
            );
        }

        return '
        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:4px">
            <thead>
                <tr style="background:#f8f8f8;border-bottom:2px solid #ddd;text-align:left">
                    <th style="padding:8px 12px;font-weight:600;color:#555">Run</th>
                    <th style="padding:8px 12px;font-weight:600;color:#555">Statut</th>
                    <th style="padding:8px 12px;font-weight:600;color:#555">Type</th>
                    <th style="padding:8px 12px;font-weight:600;color:#555">Date</th>
                    <th style="padding:8px 12px;font-weight:600;color:#555;text-align:right">URLs</th>
                    <th style="padding:8px 12px;font-weight:600;color:#555">Région</th>
                    <th style="padding:8px 12px;font-weight:600;color:#555">HIT / MISS</th>
                </tr>
            </thead>
            <tbody>' . $rows . '</tbody>
        </table>';
    }

    private function typeBadge(string $type): string
    {
        if ($type === 'full') {
            return '<span style="display:inline-block;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600;background:#ede7f6;color:#4527a0;border:1px solid #b39ddb">FULL</span>';
        }
        return '<span style="display:inline-block;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600;background:#e8eaf6;color:#283593;border:1px solid #9fa8da">INLINE</span>';
    }

    private function statusBadge(string $status): string
    {
        $styles = [
            'pending' => 'background:#fff8e1;color:#e65100;border:1px solid #ffe082',
            'running' => 'background:#e3f2fd;color:#1565c0;border:1px solid #90caf9',
            'done'    => 'background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7',
            'failed'  => 'background:#ffebee;color:#c62828;border:1px solid #ef9a9a',
        ];
        $style = $styles[$status] ?? 'background:#f5f5f5;color:#555;border:1px solid #ddd';

        return sprintf(
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;%s">%s</span>',
            $style,
            strtoupper(htmlspecialchars($status, ENT_QUOTES))
        );
    }

    private function hitRateCell(?int $hit, ?int $miss, string $hitRate): string
    {
        if ($hit === null) {
            return '<span style="color:#aaa">—</span>';
        }

        return sprintf(
            '<span style="color:#2e7d32;font-weight:600">%d HIT</span>'
            . ' <span style="color:#aaa">/</span> '
            . '<span style="color:#c62828">%d MISS</span>'
            . ' <span style="color:#888;font-size:11px">(%s)</span>',
            $hit,
            $miss,
            $hitRate
        );
    }

    private function notice(string $message): string
    {
        return sprintf(
            '<p style="color:#888;font-style:italic;margin:8px 0;font-size:13px">%s</p>',
            htmlspecialchars($message, ENT_QUOTES)
        );
    }

    private function wrapRow(string $content): string
    {
        return '<tr><td colspan="4" style="padding:10px 0">' . $content . '</td></tr>';
    }
}
