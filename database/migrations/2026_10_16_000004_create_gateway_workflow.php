<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gateway_locks', function (Blueprint $t) {
            $t->string('id')->primary();
        });
        \Illuminate\Support\Facades\DB::table('gateway_locks')->insert(['id' => 'primary']);
        Schema::create('vpn_peers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('subscription_id')->unique()->constrained()->restrictOnDelete();
            $t->string('public_key', 44)->unique();
            $t->string('gateway_identity', 64);
            $t->string('address', 15)->unique();
            $t->enum('status', ['queued', 'configuring', 'verified', 'failed', 'suspending', 'suspended'])->default('queued');
            $t->string('last_handshake')->nullable();
            $t->unsignedBigInteger('rx')->nullable();
            $t->unsignedBigInteger('tx')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('provisioning_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('vpn_peer_id')->constrained()->restrictOnDelete();
            $t->string('action');
            $t->string('status');
            $t->unsignedInteger('attempt');
            $t->string('error_code')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_jobs');
        Schema::dropIfExists('vpn_peers');
        Schema::dropIfExists('gateway_locks');
    }
};
