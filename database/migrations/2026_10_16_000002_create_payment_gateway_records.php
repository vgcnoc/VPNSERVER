<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_sessions', function (Blueprint $t) {
            $t->foreignUuid('invoice_id')->primary()->constrained()->restrictOnDelete();
            $t->text('redirect_url')->nullable();
            $t->string('status')->default('creating');
            $t->timestamps();
        });
        Schema::create('payment_webhook_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $t->string('provider');
            $t->string('transaction_id', 100);
            $t->string('status', 30);
            $t->string('outcome', 40);
            $t->timestamp('created_at');
            $t->unique(['invoice_id', 'transaction_id', 'status'], 'payment_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_sessions');
    }
};
