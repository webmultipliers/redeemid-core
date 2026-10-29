Recorded Shopify payloads for the contract tests (tests/Unit/ContractTest.php), API version 2026-10.

- Webhook files (`customers-update.json`, `customer-tags_added.json` and so on) are the sample payloads from https://shopify.dev/docs/api/webhooks/2026-10, unedited. The file name is the topic with `/` and `.` turned into `-`.
- `admin-reconcile.json` and `customer-account-identity.json` are GraphQL responses to `Admin::RECONCILE_QUERY` and `CustomerAccount::IDENTITY_QUERY`, written to the 2026-10 Admin and Customer Account API references (`null` stands for an ID Shopify didn't find).

When `Config::API_VERSION` moves, replace these with the new version's samples and rerun the tests. A failure means Shopify changed a field the mirror reads.
