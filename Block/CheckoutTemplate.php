<?php

declare(strict_types=1);

/**
 * Copyright © 2023-present ParadoxLabs, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * Need help? Try our knowledgebase and support system:
 *
 * @link https://support.paradoxlabs.com
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
