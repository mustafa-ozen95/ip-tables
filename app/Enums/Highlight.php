<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Adın vurgu rengi: listede dikkat çeksin diye ad kalın ve renkli yazılır
 * (ör. "SUNUCU KULLANMAKTA"). Değer CSS sınıfının sonekidir
 * (highlight--red; bkz. panel-custom.css).
 */
enum Highlight: string
{
    case Red = 'red';
    case Orange = 'orange';
    case Green = 'green';
    case Blue = 'blue';
    case Purple = 'purple';

    public function label(): string
    {
        return match ($this) {
            self::Red => 'Kırmızı',
            self::Orange => 'Turuncu',
            self::Green => 'Yeşil',
            self::Blue => 'Mavi',
            self::Purple => 'Mor',
        };
    }

    /** @return array<string, string> formdaki seçenekler; boş = vurgu yok */
    public static function options(): array
    {
        return ['' => 'Yok', ...collect(self::cases())->mapWithKeys(fn (self $h): array => [$h->value => $h->label()])->all()];
    }
}
