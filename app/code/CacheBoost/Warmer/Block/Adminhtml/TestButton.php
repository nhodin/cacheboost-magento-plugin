<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\FormKey;

/**
 * frontend_model for the "Tests & Diagnostic" panel in system.xml.
 * Renders three test buttons that call the AJAX controller and display results inline.
 */
class TestButton extends Field
{
    public function __construct(
        Context $context,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function render(AbstractElement $element): string
    {
        $connectionUrl = $this->getUrl('cacheboost/test/api', ['action' => 'connection']);
        $warmUrl       = $this->getUrl('cacheboost/test/api', ['action' => 'warm']);
        $boostRunUrl   = $this->getUrl('cacheboost/test/api', ['action' => 'boost_run']);
        $formKey       = htmlspecialchars($this->formKey->getFormKey(), ENT_QUOTES);

        // Translatable strings rendered into JS to avoid hardcoding a locale.
        $i18n = json_encode([
            'loading'   => (string) __('Testing…'),
            'unexpected'=> (string) __('Unexpected server response.'),
        ]);

        $lblConnection = htmlspecialchars((string) __('Test connection'), ENT_QUOTES);
        $lblWarm       = htmlspecialchars((string) __('Test inline warm'), ENT_QUOTES);
        $lblBoostRun   = htmlspecialchars((string) __('Test Boost run'), ENT_QUOTES);

        $html = <<<HTML
        <tr>
            <td colspan="4" style="padding:12px 0">
                <div id="cacheboost-test-panel"
                     style="background:#fafafa;border:1px solid #e0e0e0;border-radius:4px;padding:16px 20px">

                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
                        <button type="button"
                                onclick="cacheboostTest('{$connectionUrl}')"
                                style="padding:7px 14px;background:#1565c0;color:#fff;border:none;border-radius:3px;cursor:pointer;font-size:13px">
                            🔌 {$lblConnection}
                        </button>
                        <button type="button"
                                onclick="cacheboostTest('{$warmUrl}')"
                                style="padding:7px 14px;background:#2e7d32;color:#fff;border:none;border-radius:3px;cursor:pointer;font-size:13px">
                            🔥 {$lblWarm}
                        </button>
                        <button type="button"
                                onclick="cacheboostTest('{$boostRunUrl}')"
                                style="padding:7px 14px;background:#6a1b9a;color:#fff;border:none;border-radius:3px;cursor:pointer;font-size:13px">
                            🚀 {$lblBoostRun}
                        </button>
                    </div>

                    <div id="cacheboost-test-result"
                         style="display:none;padding:10px 14px;border-radius:3px;font-size:13px;line-height:1.5"></div>
                </div>

                <script>
                var cacheboostI18n = {$i18n};
                function cacheboostTest(url) {
                    var result = document.getElementById('cacheboost-test-result');
                    result.style.display = 'block';
                    result.style.background = '#e3f2fd';
                    result.style.border = '1px solid #90caf9';
                    result.style.color = '#1565c0';
                    result.innerHTML = '⏳ ' + cacheboostI18n.loading;

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', url, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState !== 4) return;
                        try {
                            var data = JSON.parse(xhr.responseText);
                            if (data.success) {
                                result.style.background = '#e8f5e9';
                                result.style.border     = '1px solid #a5d6a7';
                                result.style.color      = '#2e7d32';
                                result.innerHTML = '✅ ' + data.message;
                            } else {
                                result.style.background = '#ffebee';
                                result.style.border     = '1px solid #ef9a9a';
                                result.style.color      = '#c62828';
                                result.innerHTML = '❌ ' + data.message;
                            }
                        } catch (e) {
                            result.style.background = '#ffebee';
                            result.style.border     = '1px solid #ef9a9a';
                            result.style.color      = '#c62828';
                            result.innerHTML = '❌ ' + cacheboostI18n.unexpected;
                        }
                    };
                    xhr.send('form_key={$formKey}');
                }
                </script>
            </td>
        </tr>
        HTML;

        return $html;
    }
}
