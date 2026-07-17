[![Latest Stable Version](https://poser.pugx.org/paradoxlabs/clover-hyva-checkout/v/stable)](https://packagist.org/packages/paradoxlabs/clover-hyva-checkout)
[![License](https://poser.pugx.org/paradoxlabs/clover-hyva-checkout/license)](https://packagist.org/packages/paradoxlabs/clover-hyva-checkout)
[![Total Downloads](https://poser.pugx.org/paradoxlabs/clover-hyva-checkout/downloads)](https://packagist.org/packages/paradoxlabs/clover-hyva-checkout)

<p align="center">
    <a href="https://www.paradoxlabs.com"><img alt="ParadoxLabs" src="https://paradoxlabs.com/wp-content/uploads/2020/02/pl-logo-canva-2.png" width="250"></a>
</p>

This module adds support for Hyva Checkout to our Clover payment method for Magento 2.

Requirements
============

* Adobe Commerce / Magento Open Source 2.4.6 – 2.4.9 (or equivalent version of Adobe Commerce Cloud), or Mage-OS 2.0 – 3.0
* PHP 8.1, 8.2, 8.3, 8.4, or 8.5
* Hyva Checkout (separate product and license), `hyva-themes/magento2-hyva-checkout` >= 1.3
* `hyva-themes/magento2-theme-module` >= 1.3.11
* `hyva-themes/magento2-payment-icons` >= 2.0
* `paradoxlabs/clover` ^2.0

Features
========

* Place orders via Hyva Checkout, with Clover payment
* Embedded Clover SDK hosted payment fields (card number, expiration, CVV, zipcode), rendered in
  Clover-hosted iframes — card data never touches the site
* Card entry is tokenized via the Clover SDK during the Hyva place-order validation stage; the
  server charges or stores the source token
* Supports stored cards (vault) via ParadoxLabs_TokenBase
* Strict CSP and Alpine CSP compliant, for Hyva Checkout 1.3+ nonce-based CSP

Architecture
============

* `Block\CheckoutTemplate` exposes the Clover checkout config (`Model\Config\CheckoutProvider`
  from ParadoxLabs_Clover: SDK URL, public key, merchant id, locale, form style, stored cards) to
  the payment form and scripts templates.
* `Magewire\Payment\Clover` owns the stored-card list and the place-order evaluation
  (`validateparadoxlabs_clover` client validator).
* `view/frontend/templates/checkout/scripts.phtml` registers a CSP-safe Alpine component: it loads
  the Clover iframe SDK on demand (dead-SDK/load failures surface as visible errors, retryable),
  mounts the hosted fields (in a `wire:ignore` container so Magewire morphs never wipe the
  iframes, re-mounting on `checkout:payment:method-activate` after method switches), and on
  validate tokenizes the entered card via `clover.createToken()`, normalizing all documented
  failure shapes ({errors} resolution, bare field-error map rejection, Error instances).
* `Magewire\Payment\PlaceOrderService` whitelists
  `{card_id, token, cc_type, cc_exp_year, cc_exp_month, cc_last4, cc_bin, save}` — the exact
  additional_data contract of the Luma renderer — and assigns it to the quote payment via
  `importData()`, so the standard `payment_method_assign_data` observer chain runs before order
  placement. It then re-stages the raw client keys onto the payment so the second `importData()`
  that `QuoteManagement::placeOrder()` runs against the same payment instance replays identical
  data — without this, the re-import would wipe `cc_last_4`/`cc_type`/`cc_bin` for the new-card
  path (the token itself survives via Clover's `additional_information` fallback).
* Clover tokens are amount-agnostic; the charge amount is set server-side at sale (with
  idempotency-key request headers guarding retries), so no client-side total-drift handling is
  required. A token cannot be reused after a failed charge — the
  `order:place:paradoxlabs_clover:error` event clears it so the next attempt re-tokenizes.

Installation and Usage
======================

In SSH at your Magento base directory, run:

    composer require paradoxlabs/clover-hyva-checkout
    php bin/magento module:enable ParadoxLabs_CloverHyvaCheckout
    php bin/magento setup:upgrade

## Applying Updates

In SSH at your Magento base directory, run:

    composer update paradoxlabs/clover-hyva-checkout
    php bin/magento setup:upgrade

These commands will download and apply any available updates to the module.

Pre-release Caveats / TODO
==========================

* **No runtime QA yet.** This module has not been exercised against a live Hyva Checkout install
  or the Clover sandbox. Unit tests and static analysis pass, but end-to-end tokenization and
  order placement remain to be verified in a browser.
* **No stored-card CVV re-entry.** The Clover CVV field only exists inside the SDK's hosted
  iframes and cannot be collected standalone; the Luma renderer likewise never prompts for CVV on
  stored cards, so this is intentional parity with the `require_ccv` behavior there.
* **No customer-account payment options (paymentinfo) support.** The TokenBase "My Payment
  Options" page is Luma-only for Clover; this module removes its nav link on Hyva themes
  (`view/frontend/layout/hyva_customer_account.xml`). Implementing Hyva paymentinfo support is a
  future task.
* **Repository push pending.** The `m2-clover-hyva-checkout` remote repository does not exist yet.

License
=======

Apache-2.0. See LICENSE.md.
