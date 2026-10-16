<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reseller_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->enum('status', ['pending', 'approved', 'suspended'])->default('pending');
            $t->unsignedSmallInteger('commission_bps')->default(0);
            $t->timestamps();
            $t->unique(['organization_id', 'user_id']);
        });
        Schema::create('reseller_customers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('reseller_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'customer_id']);
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignUuid('reseller_account_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('commission_bps')->default(0);
        });
        Schema::create('reseller_commissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('reseller_account_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('payment_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount_idr');
            $t->enum('status', ['accrued', 'review_required'])->default('accrued');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_commissions');
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reseller_account_id');
            $t->dropColumn('commission_bps');
        });
        Schema::dropIfExists('reseller_customers');
        Schema::dropIfExists('reseller_accounts');
    }
};
