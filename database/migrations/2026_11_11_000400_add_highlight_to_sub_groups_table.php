<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alt grup başlığının vurgu rengi (bkz. App\Enums\Highlight; adreslerdeki
 * renklerle aynı). Boş: vurgu yok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_groups', function (Blueprint $table): void {
            $table->string('highlight', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sub_groups', function (Blueprint $table): void {
            $table->dropColumn('highlight');
        });
    }
};
