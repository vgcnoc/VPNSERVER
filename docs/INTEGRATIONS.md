# Integration release — 16 October 2026

## Implemented paths

- `/billing`: create global packages as Super Admin, order as a member, list own invoices; finance can see tenant invoices and verify a bank payment with a second person. Amounts are integer IDR and prices/duration are snapshotted.
- Midtrans Snap sandbox: checkout redirect, persisted checkout session, SHA-512 callback signature, independent GET-status verification, exact amount/currency check, transactional settlement and replay protection. Failed/pending/refund statuses are recorded; refunds are flagged for finance review, not automatically paid out.
- `/resellers`: apply, admin approval/suspension, admin customer assignment in the same tenant, immutable order commission rate, unique payment commission ledger. No withdrawals or deposits yet.
- `/vpn`: customer-supplied public keys, one initial peer per subscription, reserved IPv4 allocation, durable queued worker, idempotent CHR peer creation, identity/route verification and retry history.
- `vpn:reconcile`: scheduled every minute; checks peer handshake and usage; starts duration on a verified recent handshake; disables an expired peer and only then records suspended. IP reservations remain intact on failure or suspension.

## Configure Midtrans sandbox

Set `MIDTRANS_SERVER_KEY` in the ignored local `.env` to your **sandbox** Server Key. Do not send it through chat or commit it. The key must start with `SB-Mid-server-`. This release does not support Midtrans production payments.

Set Midtrans's notification URL to your application HTTPS URL followed by `/webhooks/midtrans`. This endpoint alone is exempt from CSRF. Other checkout/order/payment routes require login, email verification and CSRF. Localhost cannot receive a Midtrans webhook without an authorized reachable HTTPS development endpoint.

Run `php artisan config:clear` after local configuration changes. On an unpaid invoice owned by the signed-in customer, use **Pay with Midtrans (sandbox)**. This redirects to the real sandbox endpoint. Missing configuration returns unavailable; it never fabricates a payment result.

Reconcile a known invoice manually with `php artisan payments:reconcile INVOICE_UUID`. If checkout times out, its session remains `unknown`; do not clear it or create another order without checking Midtrans. This release does not automatically reconstruct a lost Snap token/URL. Provider errors are not evidence that a payment was not made.

Reference: https://docs.midtrans.com/docs/snap-snap-integration-guide and https://docs.midtrans.com/docs/https-notification-webhooks

## Configure a dedicated RouterOS 7 test gateway

Super Admins can now register multiple gateways from **VPN Servers** in the console. The saved password is encrypted at rest and never rendered back to the browser. Add one server, use **Test connection**, then select an active server as **Default**; new provisioning requests use that server and persist its identity with the peer. Disabling a default server intentionally stops new allocations until an active default is selected again. Existing peers remain attached to their original server.

Each server row also has **Generate CHR script**. Choose WireGuard, L2TP/IPsec, SSTP, or OpenVPN (TCP) when adding a server. The script creates its dedicated RouterOS 7 service and `.1/24` address pool; it intentionally does not alter firewall, NAT, routes, certificates, or REST exposure. L2TP/IPsec requires a pre-shared key, while SSTP and OpenVPN require the name of a certificate already installed on the CHR. The L2TP/IPsec script contains its pre-shared key and must be handled as a secret.

WireGuard remains the only protocol with automatic per-subscription customer provisioning in this release. On PHP installations without secure Curve25519 key generation, RouterOS generates the WireGuard key pair locally and prints the public key. Paste that public key back into the form before testing, activating, and selecting the WireGuard server as default. The private key remains on the CHR. L2TP/IPsec, SSTP, and OpenVPN server scripts can be monitored and activated, but their per-customer PPP account lifecycle is not yet automated.

Direct CHR WireGuard management is implemented; CHR bootstrap/health checks are available for L2TP/IPsec, SSTP and OpenVPN. Linux WireGuard, failover and arbitrary RouterOS scripting are not implemented. Before enabling gateway writes:

1. Confirm this is an authorized test gateway. Take and retain a CHR backup independently before changing network settings.
2. Reserve an exclusive /24 VPN subnet and its gateway at `.1` for every server. Set restrictive firewall and routing rules for the intended remote-management destinations. For SSTP/OpenVPN, install and validate the server certificate on the CHR before generating its script. The application does not create firewall/NAT rules, certificates, or an internet exit gateway.
3. Make RouterOS HTTPS REST reachable over a restricted management path. Trust its CA/certificate on the application host; TLS verification is always enabled. Do not expose management ports broadly.
4. Configure `ROUTEROS_URL` as the HTTPS origin (without `/rest`), `ROUTEROS_USERNAME`, `ROUTEROS_PASSWORD`, `ROUTEROS_WG_INTERFACE`, `ROUTEROS_WG_PUBLIC_KEY`, `ROUTEROS_POOL_PREFIX` (e.g. `10.80.0` for an exclusively assigned `10.80.0.0/24`), and `ROUTEROS_CAPACITY`. Credentials stay in the ignored environment, not browser inputs.
5. Only after review set `ROUTEROS_ENABLED=true`. These settings authorize the worker to create peers and the scheduler to disable expired peers on this gateway. Do not point this sandbox billing installation at a production gateway.

The pool allocator reserves `.2` through `.254` and serializes allocations with a database lock plus unique constraints. It supports one dedicated pool, not multi-server overlap detection. The gateway must not contain independently managed peers using this pool. Capacity counts allocated records, including failures and suspended peers.

Client keys are created on the client device/CHR and only the public key is submitted. Private-key generation, configuration downloads, QR codes and client RouterOS setup scripts are not provided by this release. Configure the client interface and routing manually using the allocated IP and approved gateway endpoint. A peer handshake does not prove application/network reachability.

Reference: https://help.mikrotik.com/docs/spaces/ROS/pages/47579162/REST+API, https://help.mikrotik.com/docs/spaces/ROS/pages/69664792/WireGuard, https://help.mikrotik.com/docs/spaces/ROS/pages/11993097/IPsec, https://help.mikrotik.com/docs/spaces/ROS/pages/2031645/SSTP, and https://help.mikrotik.com/docs/spaces/ROS/pages/2031655/OpenVPN

## Workers, scheduler and deployment

Local development terminals:

```text
php artisan queue:work --tries=3 --timeout=80
php artisan schedule:work
```

The scheduler runs `vpn:reconcile` every minute for existing peers and `vpn:check-servers` every five minutes to refresh the server health shown in **VPN Servers**. The health check only reads RouterOS state; it never creates, enables, or disables peers.

Production service installation has not been performed. On a future reviewed release, run the queue worker under Supervisor/systemd and scheduler with `* * * * * php /path/to/artisan schedule:run`. Keep queue retry-after greater than worker timeout. Provisioning holds a primary gateway row lock during remote operations, so throughput is intentionally limited. Queue dispatch after commit can be recovered by the reconciler for `queued` peers. Failed jobs need a reviewed retry; ambiguous upstream state retains the address and identity.

Apply new migrations with `php artisan migrate` after a database backup. Do not use `migrate:fresh` on customer data. Build assets with `npm ci` then `npm run build`; use the `public` directory as web root. Keep `.env`, database files, credentials and vendor/node_modules out of Git. No VPS changes or new GitHub push were made as part of this integration implementation.

## Acceptance limits and next work

Automated tests use in-memory SQLite and fake HTTP/gateway responses. They verify workflows and authorization, not a working WireGuard tunnel or a successful sandbox transaction. Run separate MariaDB concurrency tests and browser/end-to-end tests before production acceptance.

Outstanding: renewal invoices and reactivation, reminder delivery, plan edit/deactivate, device/peer quotas beyond the first peer, router backup/restore automation, key rotation/revocation and configuration download, granular tenant-scoped staff roles, multiple gateways/IP pools, monitoring history and failover, reseller deposits/payouts, refund execution, production deployment. A sandbox payment can create test accounting entries but must never be treated as real revenue or a payable commission.

New migration files: `2026_10_16_000001_create_commercial_core`, `000002_create_payment_gateway_records`, `000003_create_reseller_ledger`, `000004_create_gateway_workflow`. Implementation is separated across Billing/Midtrans/Reseller/Vpn services and controllers, a queue job and reconciliation commands. Tests are in `BillingTest`, `MidtransTest`, `ResellerTest`, `VpnWorkflowTest`, and `RouterOsAdapterTest`.
