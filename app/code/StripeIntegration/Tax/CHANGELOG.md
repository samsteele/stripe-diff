# Changelog

## 1.1.0 - 2025-03-12

- Added tax exemptions based on customer groups.
- Added CLI command which reverts the tax for a specific order item.
- Added customer address verification at the checkout level.

## 1.0.3 - 2025-01-10

- Fixed issue where the tax breakdown coming back from Stripe contains two identical records.
- Added MFTF tests.

## 1.0.2 - 2024-11-20

- Fixed issue when the currencies being used have different precisions for the Stripe API.
- Use the inverse of the base to current currency instead of the rate saved in the DB, in case the rates are not the exact inverse of on another.

## 1.0.1 - 2024-10-22

- Added the possibility for a 3rd party developer to add a custom fee in the tax calculation process.
- Added instructions on how the 3rd party developer can integrate their custom fee in `resources/cookbooks/Integrate_Custom_Fee.md`.

## 1.0.0 - 2024-09-10

Initial release. Supports Adobe Commerce and Magento Open Source 2.3.7 - 2.4.7. Supported PHP versions are 7.4 - 8.3.