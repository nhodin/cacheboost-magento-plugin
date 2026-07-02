<?php

declare(strict_types=1);

namespace CacheBoost\Warmer\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * frontend_model for the "Tests & Diagnostic" panel in system.xml.
 * Renders three test buttons that call the AJAX controller and display results inline.
 */
class TestButton extends Field
{
    public function __construct(
        Context $context,
        private readonly FormKey $formKey,
        private readonly SecureHtmlRenderer $secureHtmlRenderer,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setTemplate('CacheBoost_Warmer::system/config/test_buttons.phtml');
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function render(AbstractElement $element): string
    {
        return '<tr><td colspan="4" style="padding:12px 0">' . $this->toHtml() . '</td></tr>';
    }

    /**
     * URL of the AJAX test controller for the given test action.
     */
    public function getActionUrl(string $action): string
    {
        return $this->getUrl('cacheboost/test/api', ['action' => $action]);
    }

    /**
     * Form key sent with every test POST request.
     */
    public function getFormKeyValue(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * Renderer used by the template to output a CSP-whitelisted script tag.
     */
    public function getSecureRenderer(): SecureHtmlRenderer
    {
        return $this->secureHtmlRenderer;
    }
}
