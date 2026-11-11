<?php

declare(strict_types=1);

use App\Enums\BadgeVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Roller ve yerleşik üç rol. Yetkiler (template) config/permissions.php'deki
 * şablonlardan gelir; rol ekranından sonradan değiştirilebilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('slug', 40)->unique();
            $table->string('label');
            $table->string('description')->nullable();
            $table->string('badge', 20)->default(BadgeVariant::Gray->value);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_default')->default(false);
            $table->json('template')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->after('email')->constrained('roles');
        });

        $this->seedBuiltIn();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('roles');
    }

    private function seedBuiltIn(): void
    {
        $templates = (array) config('permissions.templates', []);

        $roles = [
            [
                'slug' => 'admin', 'label' => 'Yönetici', 'badge' => BadgeVariant::Purple->value,
                'is_admin' => true, 'position' => 0,
                'description' => 'Bütün yetki kontrollerini geçer. En az bir yönetici rolü kalmak zorundadır.',
            ],
            /*
             * Adlar Kimlik Yönetim Merkezi'ndeki rollerle BİREBİR aynıdır:
             * merkezden giren kişi rolüyle eşleşir. Karşılığı olmayan rol
             * varsayılana düşerdi.
             */
            [
                'slug' => 'network', 'label' => 'Network', 'badge' => BadgeVariant::Blue->value,
                'is_admin' => false, 'position' => 1,
                'description' => 'Bütün gruplarda adresleri görür; yalnız ad ve açıklamayı düzenler.',
            ],
            [
                'slug' => 'teknik', 'label' => 'Teknik', 'badge' => BadgeVariant::Gray->value,
                'is_admin' => false, 'position' => 2, 'is_default' => true,
                'description' => 'Bütün gruplarda adresleri görür; değiştirmez.',
            ],
            [
                'slug' => 'muhasebe', 'label' => 'Muhasebe', 'badge' => BadgeVariant::Cyan->value,
                'is_admin' => false, 'position' => 3,
                'description' => 'Bütün gruplarda adresleri görür; değiştirmez.',
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->insert([
                'is_default' => false,
                ...$role,
                'uuid' => (string) Str::uuid7(),
                'is_system' => true,
                'template' => json_encode($templates[$role['slug']] ?? [], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
