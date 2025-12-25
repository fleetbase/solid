<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('solid_identities', function (Blueprint $table) {
            $table->string('css_email')->nullable()->after('identifier');
            $table->text('css_password')->nullable()->after('css_email');
            $table->string('css_client_id')->nullable()->after('css_password');
            $table->text('css_client_secret')->nullable()->after('css_client_id');
            $table->string('css_client_resource_url')->nullable()->after('css_client_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solid_identities', function (Blueprint $table) {
            $table->dropColumn(['css_email', 'css_password', 'css_client_id', 'css_client_secret', 'css_client_resource_url']);
        });
    }
};
