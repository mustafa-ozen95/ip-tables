<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Gerekçe alanının kuralı.
 *
 * Silme gibi geri alınamayan işlemler gerekçe ister; değer etkinlik
 * kaydına yazılır. Onay penceresi (confirm.js) alanı forma "reason" adıyla
 * ekler; pencereyi atlayan bir istek de burada reddedilir.
 */
final class ReasonRule
{
    public const EN_AZ = 3;

    public const EN_COK = 190;

    /** @return array<int, string> */
    public static function zorunlu(): array
    {
        return ['required', 'string', 'min:'.self::EN_AZ, 'max:'.self::EN_COK];
    }
}
