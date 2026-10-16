<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->string('protocol', 32)->default('wireguard')->after('name')->index();
            $table->text('protocol_secret')->nullable()->after('password');
            $table->string('certificate_name', 128)->nullable()->after('protocol_secret');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_servers', function (Blueprint $table) {
            $table->dropColumn(['protocol', 'protocol_secret', 'certificate_name']);
        });
    }
};
