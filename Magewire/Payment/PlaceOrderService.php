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

namespace ParadoxLabs\CloverHyvaCheckout\Magewire\Payment;

use Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote;
use ParadoxLabs\Clover\Model\Config\Config;

class PlaceOrderService extends AbstractPlaceOrderService
{
    /**
     * Clover iframe SDK additional_data contract, mirroring the Luma renderer's getData():
     * exactly one of {token, card_id} populated per submit, plus the card metadata returned by
     * createToken() (brand, expiration, first6/last4) and the save-card flag.
     */
    private const ALLOWED_KEYS
        = [
            'card_id' => null,
            'token' => null,
            'cc_type' => null,
            'cc_exp_year' => null,
            'cc_exp_month' => null,
            'cc_last4' => null,
            'cc_bin' => null,
            'save' => null,
        ];

    /**
     * Assign the client payment data to the quote payment, then place the order.
     *
     * importData() (not addData()) so the payment_method_assign_data observer chain runs — that is
     * what copies token into additional_information, composes cc_number from cc_bin + cc_last4
     * (setting cc_last_4 and, when enabled, the cc_bin additional data), and resolves card_id
     * into tokenbase_id.
     *
     * QuoteManagement::placeOrder() then runs a second importData() against the same payment
     * instance, fed from $payment->getData(). Clover's assign observer keys on the RAW client
     * field names (token, cc_bin, cc_last4) — none of which survive as payment data — so without
     * re-staging, that second import would wipe cc_last_4/cc_type/cc_bin for the new-card path
     * (the token itself survives via the additional_information fallback). Re-staging the
     * whitelisted client keys onto the payment makes the second import replay the exact same
     * data, so both passes produce identical state. The stored-card path self-heals either way:
     * TokenBase reloads the card from the already-assigned tokenbase_id and re-derives its
     * metadata.
     *
     * @throws CouldNotSaveException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function placeOrder(Quote $quote): int
    {
        $paymentData = (array)$this->getData()->getPayment();

        // Only pass through known allowed values, to prevent parameter injection
        $knownPaymentData = array_intersect_key(
            $paymentData,
            self::ALLOWED_KEYS,
        );

        $payment = $quote->getPayment();

        $payment->importData([
            PaymentInterface::KEY_METHOD => Config::CODE,
            PaymentInterface::KEY_ADDITIONAL_DATA => $knownPaymentData,
        ]);

        // Re-stage the raw client keys for the second importData() (see docblock). In-memory
        // only: none of these keys map to quote_payment columns.
        $payment->addData($knownPaymentData);

        return parent::placeOrder($quote);
    }
}
