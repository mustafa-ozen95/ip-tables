<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kayda geçen işlem.
 *
 * Revealed ("parola görüntülendi") diğerlerinden farklıdır: hiçbir şeyi
 * değiştirmez ama denetimde en çok sorulan sorudur — bu yüzden kaydedilir.
 */
enum ActivityAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';

    case LoggedIn = 'logged_in';
    case LoginFailed = 'login_failed';

    /* Denetim satırı silmek de bir işlemdir ve kaydı zincire yazılır. */
    case Pruned = 'pruned';

    /* Panel güncellemesi başlatıldı (platformun deploy hook'u tetiklendi). */
    case UpdateStarted = 'update_started';

    /* Liste Excel dosyası olarak indirildi: hiçbir şey değişmez ama veri panelden çıkar. */
    case Exported = 'exported';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Oluşturuldu',
            self::Updated => 'Güncellendi',
            self::Deleted => 'Silindi',
            self::LoggedIn => 'Giriş yapıldı',
            self::LoginFailed => 'Başarısız giriş',
            self::Pruned => 'Kayıt budandı',
            self::UpdateStarted => 'Güncelleme başlatıldı',
            self::Exported => 'Dışa aktarıldı',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Created => BadgeVariant::Green,
            self::Updated => BadgeVariant::Blue,
            self::Deleted, self::LoginFailed, self::Pruned => BadgeVariant::Red,
            self::UpdateStarted => BadgeVariant::Blue,
            self::LoggedIn, self::Exported => BadgeVariant::Gray,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $action): array => [$action->value => $action->label()])
            ->all();
    }
}
