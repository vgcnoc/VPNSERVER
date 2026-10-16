<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->string('client_endpoint', 255)->nullable()->after('endpoint');
        });

        Schema::table('vpn_peers', function (Blueprint $table) {
            $table->string('protocol', 32)->default('wireguard')->after('vpn_server_id')->index();
            $table->string('username', 64)->nullable()->unique()->after('public_key');
            $table->text('connection_secret')->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_peers', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['protocol', 'username', 'connection_secret']);
        });

        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->dropColumn('client_endpoint');
        });
    }
};
