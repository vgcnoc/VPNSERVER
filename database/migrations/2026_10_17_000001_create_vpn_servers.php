<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vpn_servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('endpoint', 2048)->unique();
            $table->string('username', 255);
            $table->text('password');
            $table->string('wireguard_interface', 64);
            $table->string('wireguard_public_key', 44)->nullable();
            $table->string('pool_prefix', 15);
            $table->unsignedSmallInteger('capacity')->default(100);
            $table->enum('status', ['draft', 'active', 'disabled'])->default('draft')->index();
            $table->boolean('is_default')->default(false)->index();
            $table->enum('health_status', ['unknown', 'healthy', 'unreachable', 'misconfigured'])->default('unknown')->index();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 160)->nullable();
            $table->timestamps();
        });

        Schema::table('vpn_peers', function (Blueprint $table) {
            $table->foreignUuid('vpn_server_id')->nullable()->after('subscription_id')->constrained('vpn_servers')->nullOnDelete();
            $table->index('vpn_server_id');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_peers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vpn_server_id');
        });
        Schema::dropIfExists('vpn_servers');
    }
};
