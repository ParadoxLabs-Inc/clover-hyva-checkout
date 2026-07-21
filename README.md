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
* `paradoxlabs/clover` ^2.1 (the not-yet-released iframe-reliability line: hardened SDK loading,
  locale support, tokenization error handling, idempotency keys)

Features
========

* Place orders via Hyva Checkout, with Clover payment
* Embedded Clover SDK hosted payment fields (card number, expiration, CVV, zipcode), rendered in
  Clover-hosted iframes — card data never touches the site
* Card entry is tokenized via the Clover SDK during the Hyva place-order validation stage; the
  server charges or stores the source token
* Supports stored cards (vault) via ParadoxLabs_TokenBase
* Customer-account "My Payment Data" add/edit/delete on Hyva themes (via
  ParadoxLabs_TokenBaseHyvaCheckout), with the same Clover SDK hosted fields
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
  required. A token cannot be reused after a failed charge — the new-card path re-tokenizes on
  every validation pass, and the `order:place:paradoxlabs_clover:error` event clears any retained
  token as belt-and-braces.
* `PlaceOrderService::handleException()` accepts placement failures instead of rethrowing:
  Magewire's exception path returns bare `{message, code}` JSON with no effects, which would drop
  the queued `order:place:*:error` browser events and (in production) swallow the decline message
  behind a generic reload dialog. The failure is surfaced as a friendly error message via
  `evaluateCompletion()`, and `canRedirect()` suppresses the success redirect for the failed
  attempt.
* Customer-account payment management (`customer/paymentinfo`) is delivered by the shared
  ParadoxLabs_TokenBaseHyvaCheckout module (wrapper/tabs, card list + delete modal, two-step form
  scaffold, shared `billing[...]` address fieldset). This module contributes only the Clover
  specifics: `paradoxlabs_clover` is appended to the shared `SupportedMethods` view model
  (`etc/frontend/di.xml`), and `hyva_customer_paymentinfo_index_paradoxlabs_clover.xml` retemplates
  the method/cards/form blocks to the shared templates plus a `payment_pane` child
  (`view/frontend/templates/customer/form/cc.phtml`). Retemplating (rather than remove-and-replace)
  is safe because `ParadoxLabs\Clover\Block\Customer\Form` does not override `_toHtml()`/`_template`.
  The pane mounts the Clover hosted fields on the scaffold's
  `paradoxlabs_cloverPaymentinfoAddressConfirmed` window event, tears them down on
  `...AddressEdit`, and on Save tokenizes via `clover.createToken()`, writes the token + card
  metadata into the hidden `payment[token|cc_type|cc_bin|cc_last4|cc_exp_year|cc_exp_month]`
  inputs, and submits the form (the card hash rides in the scaffold's hidden `id` field so an edit
  updates the existing card). Requires an edit-path fix in `paradoxlabs/clover` `^2.1` so a
  re-entered card actually re-tokenizes on edit (`PaymentMethodAssignDataObserver` now processes a
  posted token on a `tokenbase_source=paymentinfo` save even when `card_id` identifies the edited
  row).

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
  or the Clover sandbox. Unit tests and static analysis pass, but the following remain to be
  verified in a browser on a Hyva theme:
    * Checkout: new-card tokenization + order placement; stored-card placement; decline message
      surfacing; method switch re-mount.
    * Payment options page (`customer/paymentinfo`): nav link present; tabs across 2+ active
      methods; card list + delete confirm modal.
    * Add card: address step -> Confirm Address -> hosted fields mount -> Save tokenizes ->
      redirect + "Payment data saved successfully" message; card appears in the list.
    * Edit card: form prefilled from the card address; re-entering a card and Saving updates the
      existing row (not a duplicate) and refreshes the gateway token/expiry.
    * Address edit after confirm tears the hosted fields down; re-confirm re-mounts cleanly.
    * SDK load failure (blocked script) surfaces the visible retryable error instead of a dead
      form; region switcher for US/CA/intl; `window.dispatchMessages` availability on the account
      page.
* **No stored-card CVV re-entry.** The Clover CVV field only exists inside the SDK's hosted
  iframes and cannot be collected standalone; the Luma renderer likewise never prompts for CVV on
  stored cards, so this is intentional parity with the `require_ccv` behavior there.
* **Repository push pending.** The `m2-clover-hyva-checkout` remote repository does not exist yet.

License
=======

Apache-2.0. See LICENSE.md.
