# ParadoxLabs_CloverHyvaCheckout Changelog

## 1.1.0 - Jul 17, 2026

Add customer-account payment management (paymentinfo) support on Hyva themes via the shared
ParadoxLabs_TokenBaseHyvaCheckout module. Appends `paradoxlabs_clover` to the shared
`SupportedMethods` view model and retemplates the method/cards/form blocks to the shared templates
plus a Clover SDK payment pane (`view/frontend/templates/customer/form/cc.phtml`). The pane mounts
the Clover hosted fields on address confirm, tears them down on address edit, and on Save tokenizes
via `clover.createToken()` and submits. Requires `paradoxlabs/clover` `^2.1` including the
edit-path re-tokenization fix.

## 1.0.0 - Jul 17, 2026

Initial release: Clover SDK hosted payment fields support for Hyva Checkout, with stored cards
(vault) and strict CSP/Alpine CSP compliance. Customer-account payment options (paymentinfo) are
not yet supported on Hyva for Clover; the shared "My Payment Data" page shows an unavailable
notice for Clover until its paymentinfo migration lands.
