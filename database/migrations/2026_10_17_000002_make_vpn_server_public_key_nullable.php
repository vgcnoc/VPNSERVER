<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->string('wireguard_public_key', 44)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->string('wireguard_public_key', 44)->nullable(false)->change();
        });
    }
};
