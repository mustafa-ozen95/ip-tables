<?php

declare(strict_types=1);

use App\Enums\GroupKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Gruplar, alt gruplar ve adresler.
 *
 * Grup YETKİ SINIRIDIR: kullanıcıya adres değil grup verilir. Türü (kind)
 * gruba hangi adreslerin girebileceğini söyler; dört yerleşik grup kurulumla
 * gelir, yenileri ekranda açılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('address_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('name')->unique();
            $table->string('kind', 30)->default(GroupKind::Mixed->value);
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['position', 'name']);
        });

        Schema::create('sub_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('address_group_id')->constrained('address_groups')->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['address_group_id', 'name']);
            $table->index(['address_group_id', 'position']);
        });

        Schema::create('ip_addresses', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('address_group_id')->constrained('address_groups')->cascadeOnDelete();
            $table->foreignId('sub_group_id')->nullable()->constrained('sub_groups')->nullOnDelete();
            // Kanonik biçim (inet_ntop). Tek adres tam önekli (/32, /128) saklanır:
            // "10.0.0.1" ile "10.0.0.1/32" aynı kayıttır.
            $table->string('address', 45);
            $table->unsignedTinyInteger('prefix');
            // inet_pton: metin sırası 10.0.0.10'u 10.0.0.9'un önüne koyar, ikili sıra koymaz.
            $table->binary('sort_key', 16);
            $table->string('name', 120)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            // Aynı adres aynı grupta bir kez; farklı gruplarda (ör. ofis ve
            // müşteri listesi) yer alabilir.
            $table->unique(['address_group_id', 'address', 'prefix']);
            $table->index(['address_group_id', 'sub_group_id', 'sort_key']);
        });

        $this->seedBuiltInGroups();
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
        Schema::dropIfExists('sub_groups');
        Schema::dropIfExists('address_groups');
    }

    private function seedBuiltInGroups(): void
    {
        foreach (GroupKind::builtIn() as $position => $kind) {
            DB::table('address_groups')->insert([
                'uuid' => (string) Str::uuid7(),
                'name' => $kind->label(),
                'kind' => $kind->value,
                'description' => $kind->description(),
                'position' => $position,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
