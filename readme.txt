=== WPCalibrate Accounting Sync ===
Contributors: wpcalibrate
Donate link: https://wpcalibrate.com/
Tags: woocommerce, quickbooks, quickbooks online, accounting, sync
Requires at least: 6.4
Tested up to: 7.1.2
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects WooCommerce with QuickBooks Online for automatic, asynchronous accounting synchronization without modifying core files.

== Description ==

**WPCalibrate Accounting Sync** is a WooCommerce to QuickBooks Online integration plugin built to export store accounting data reliably.

The plugin communicates with the official QuickBooks Online REST APIs and uses OAuth 2.0 to connect your WooCommerce store to your QuickBooks Online company.

### Key Features

* **OAuth 2.0 Connection:** Secure authorization using official Intuit endpoints.
* **Sandbox & Production Modes:** Strict physical and logical separation of credentials, tokens, mappings, and logs.
* **Order Accounting Modes:**
  * **Sales Receipt Mode:** Creates paid Sales Receipts in QuickBooks Online for completed transactions.
  * **Invoice + Payment Mode:** Creates accounts receivable Invoices and links Payments only when WooCommerce payment status confirms settlement.
* **Asynchronous Background Processing:** Uses WooCommerce Action Scheduler for background processing so store checkouts are never blocked by external API requests.
* **Idempotency & Duplicate Prevention:** Persistent entity ledger guarantees that retries and concurrent events do not produce duplicate accounting entries in QuickBooks.
* **Full and Partial Refunds:** Export WooCommerce refunds as QuickBooks Refund Receipts linked to the original transaction.
* **Bidirectional Inventory Synchronization:** Optional stock synchronization with origin markers to prevent infinite echo loops.
* **Webhook Receiver:** Inbound REST webhook endpoint verifying Intuit HMAC-SHA256 signatures for near-real-time synchronization.
* **Scheduled Reconciliation:** Periodic sliding-window reconciliation engine to catch missed events.
* **Historical Synchronization:** Safe, chunked historical batch export with pre-flight dry-run estimation.
* **HPOS Compatibility:** Fully compatible with WooCommerce High-Performance Order Storage (HPOS) and legacy order storage.

### Important Service Disclosures & Privacy

This plugin connects your WordPress website to **QuickBooks Online**, a third-party accounting service operated by Intuit Inc.

* **Third-Party Service:** QuickBooks Online (Intuit Inc.)
* **Service Terms of Service:** https://www.intuit.com/legal/terms/
* **Service Privacy Statement:** https://www.intuit.com/privacy/statement/
* **What Data is Sent:** Order numbers, order line items (product names, SKUs, quantities, unit prices), shipping amounts, discount amounts, tax classes, customer display names, billing addresses, and payment gateway references.
* **When Data is Sent:** When an order transitions to a configured export status, when a refund is issued, when inventory quantities change (if enabled), or when an administrator executes a manual or historical sync.
* **Credentials:** OAuth 2.0 tokens are encrypted using AES-256 with WordPress salt keys and stored in your local WordPress database. Tokens and secrets are never transmitted to third parties other than Intuit authorization endpoints.

### Trademark Notice

QuickBooks and Intuit are registered trademarks of Intuit Inc. WPCalibrate Accounting Sync is an independent integration developed by WPCalibrate and is not owned by, officially affiliated with, or endorsed by Intuit Inc.

== Installation ==

1. Ensure your server runs PHP 8.2 or 8.3 and WooCommerce 8.0 or higher.
2. Upload the `wpcalibrate-accounting-sync` folder to your `/wp-content/plugins/` directory, or upload the ZIP file through **Plugins > Add New > Upload Plugin**.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Go to **WPCalibrate > Accounting Sync > Connection**.
5. Copy the Redirect URI displayed on the screen and add it to your Intuit Developer App (at developer.intuit.com).
6. Enter your Intuit App **Client ID** and **Client Secret**, save settings, and click **Connect to QuickBooks**.
7. Navigate to the **Mapping** tab to map your default accounts, items, payment methods, and tax codes.

== Frequently Asked Questions ==

= Does this plugin support QuickBooks Desktop? =
No. Version 1.0.0 exclusively supports QuickBooks Online via official REST APIs. QuickBooks Desktop is out of scope.

= Does this plugin slow down WooCommerce checkout? =
No. All synchronization operations are offloaded to WooCommerce Action Scheduler and processed in the background.

= How does the plugin prevent duplicate transactions? =
The plugin maintains a custom entity mapping table. Before any transaction is created in QuickBooks, the plugin checks if a mapping already exists. Concurrency mutex locks prevent simultaneous execution of identical order jobs.

= What happens if I uninstall the plugin? =
By default, all plugin mappings, settings, and logs are preserved in your database in case you reinstall. If you enable "Delete plugin data on uninstall" under Sync Settings, local custom tables and stored credentials will be removed upon deletion. Transactions already created inside your QuickBooks Online company are never deleted.

== Changelog ==

= 1.0.0 =
* Initial commercial release.
* Official Intuit OAuth 2.0 authentication flow with automatic token refresh.
* Strict Sandbox and Production environment segregation.
* Sales Receipt and Invoice + Payment accounting modes.
* WooCommerce CRUD and HPOS compatibility.
* WooCommerce Action Scheduler asynchronous background queues.
* Full and partial refund synchronization.
* Inventory synchronization with echo-loop prevention.
* Inbound Intuit webhook verification endpoint (HMAC-SHA256).
* Scheduled sliding-window reconciliation engine.
* Historical synchronization engine with dry-run estimation.
* Comprehensive audit logging with automated secrets redaction.
* Diagnostics and system status reporting.
