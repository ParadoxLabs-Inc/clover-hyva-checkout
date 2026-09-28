<?php

declare(strict_types=1);

/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <info@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\CloverHyvaCheckout\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use ParadoxLabs\Clover\Model\Config\CheckoutProvider;
use ParadoxLabs\Clover\Model\Config\Config;
use Throwable;

class CheckoutTemplate extends Template
{
    /**
     * Constructor
     *
     * @param Context $context
     * @param CheckoutProvider $configProvider
     * @param array $data
     */
    public function __construct(
        Context $context,
        protected readonly CheckoutProvider $configProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get payment method code for the active/relevant method
     *
     * @return string
     */
    public function getMethodCode(): string
    {
        return $this->getData('method_code') ?? Config::CODE;
    }

    /**
     * Get payment form config object
     *
     * @return array
     */
    public function getConfig(): array
    {
        try {
            $config = $this->configProvider->getConfig();
        } catch (Throwable) {
            // Method active but misconfigured (e.g. missing API keys): render nothing rather
            // than break the whole checkout page.
            return [];
        }

        return $config['payment'][ $this->getMethodCode() ] ?? [];
    }
}
