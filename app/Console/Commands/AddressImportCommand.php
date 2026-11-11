<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AddressImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Sekmeyle ayrılmış listeden toplu adres yükleme (bkz. AddressImport).
 *
 *   php artisan addresses:import liste.tsv --kullanici=ad@ornek.test            (önizleme)
 *   php artisan addresses:import liste.tsv --kullanici=ad@ornek.test --yaz      (yazar)
 *   php artisan addresses:import liste.tsv --kullanici=… --yaz --hepsini-sil    (önce bütün adresleri siler)
 *
 * --yaz olmadan hiçbir şey yazılmaz. İşlem geçmişine --kullanici olarak düşer.
 */
class AddressImportCommand extends Command
{
    protected $signature = 'addresses:import
        {dosya : sekmeyle ayrılmış liste: grup, alt grup, adres, ad, açıklama, vurgu}
        {--kullanici= : işlemi yapan kullanıcının e-postası (işlem geçmişi)}
        {--yaz : önizleme değil, yaz}
        {--hepsini-sil : yazmadan önce BÜTÜN adres kayıtlarını sil}';

    protected $description = 'Sekmeyle ayrılmış listeden toplu adres yükler (önce önizler)';

    public function handle(AddressImport $import): int
    {
        $user = User::query()->where('email', (string) $this->option('kullanici'))->first();

        if ($user === null) {
            $this->error('--kullanici ile var olan bir kullanıcının e-postası verilmeli.');

            return self::FAILURE;
        }

        if (! is_readable($path = (string) $this->argument('dosya'))) {
            $this->error("Dosya okunamadı: {$path}");

            return self::FAILURE;
        }

        $replace = (bool) $this->option('hepsini-sil');
        ['rows' => $rows, 'errors' => $errors] = $import->read((string) file_get_contents($path), $replace);

        foreach ($errors as $error) {
            $this->error($error);
        }

        if ($errors !== []) {
            $this->error(count($errors).' hata; hiçbir şey yazılmadı.');

            return self::FAILURE;
        }

        $this->table(['Grup', 'Alt grup', 'Adres', 'Ad', 'Vurgu'], array_map(fn (array $r): array => [
            $r['group'], $r['subGroup'] ?? '—', $r['parsed']->notation(), $r['name'] ?? '—', $r['highlight'] ?? '—',
        ], $rows));
        $this->info(count($rows).' kayıt'.($replace ? '; önce bütün adresler silinecek.' : '.'));

        if (! $this->option('yaz')) {
            $this->warn('Önizleme: yazmak için --yaz ekleyin.');

            return self::SUCCESS;
        }

        /* İşlem geçmişi yapanı isteğin kullanıcısından okur (ActivityRecorder); konsolda isteği bu kullanıcı yapar. */
        Auth::setUser($user);
        app('request')->setUserResolver(fn (): User => $user);
        ['deleted' => $deleted, 'created' => $created] = $import->write($rows, $replace, 'Toplu yükleme: liste yeniden işlendi');
        $this->info("{$deleted} kayıt silindi, {$created} kayıt eklendi.");

        return self::SUCCESS;
    }
}
