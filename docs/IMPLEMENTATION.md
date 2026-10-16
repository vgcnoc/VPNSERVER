# Implementation sequence

The next release is delivered in reviewable stages:

1. Commercial core: tenant-scoped orders, immutable invoice price snapshots, manual payment approval, subscription lifecycle and tests.
2. Provider integration: select Midtrans or Xendit, implement against official sandbox API, verify signed callbacks, reconciliation and duplicate delivery.
3. Network engine: validated gateway inventory, transactional IPAM, durable provisioning attempts, authorized agent/SSH transport, peer verification and encrypted configurations.
4. Automation: renewal invoices and reminders, expiration, queued suspension/reactivation, bounded retries and reconciliation.
5. Router integration: RouterOS compatibility, configuration preview and backup, authorized changes and verification.
6. Reseller: scoped ownership, transactional commission ledger, reversal/payout workflow and tests.

Never activate a subscription on payment alone. A payment moves it to paid; verified provisioning is required for active. Expiry jobs must not claim a peer was suspended before the gateway confirms removal. Real network/provider acceptance is separate from mocked integration and unit tests. Secrets are supplied via local/server configuration, never committed.
