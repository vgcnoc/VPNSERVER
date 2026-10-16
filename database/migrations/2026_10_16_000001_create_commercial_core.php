<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->unsignedBigInteger('price_idr');
            $t->unsignedInteger('duration_days');
            $t->unsignedInteger('peer_limit')->default(1);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $t->string('idempotency_key', 64);
            $t->enum('status', ['pending_payment', 'paid'])->default('pending_payment');
            $t->timestamps();
            $t->unique(['organization_id', 'idempotency_key']);
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('order_id')->unique()->constrained()->restrictOnDelete();
            $t->enum('status', ['pending_payment', 'paid', 'provisioning', 'active', 'past_due', 'suspending', 'suspended', 'terminating', 'terminated'])->default('pending_payment');
            $t->unsignedInteger('duration_days');
            $t->unsignedInteger('peer_limit');
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
            $t->index(['organization_id', 'status']);
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('order_id')->unique()->constrained()->restrictOnDelete();
            $t->string('description');
            $t->unsignedBigInteger('amount_idr');
            $t->enum('status', ['unpaid', 'paid'])->default('unpaid');
            $t->timestamp('due_at');
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('invoice_id')->unique()->constrained()->restrictOnDelete();
            $t->string('provider');
            $t->string('reference', 100);
            $t->unsignedBigInteger('amount_idr');
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at');
            $t->timestamps();
            $t->unique(['provider', 'reference']);
        });
        Schema::create('subscription_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('subscription_id')->constrained()->restrictOnDelete();
            $t->string('from_status');
            $t->string('to_status');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['subscription_events', 'payments', 'invoices', 'subscriptions', 'orders', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
