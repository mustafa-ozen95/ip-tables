<x-layout.app title="Kayıtlar">
    <div class="page">
        <div class="page__inner page__inner--wide">
            <x-ui.page-head title="Denetim kayıtları"
                            subtitle="Yalnızca okunur. Silme ucu yoktur: kaydı silebilen bir denetim kaydı, denetim kaydı değildir." />

            <x-logs.tabs />

            <x-ui.flash />

            {{--
                Zincir durumu. Amaç kaydı gizlemek değil, DEĞİŞTİRİLMEDİĞİNİ
                kanıtlayabilmek. Doğrulama her sayfa açılışında değil, istekle
                ve KUYRUKTA çalışır: yüz bin satırı yeniden özetlemek dakikalar
                sürer, tarayıcı onu bekleyemez.
            --}}
            <div @class([
                'chain',
                'chain--ok' => $verification && $verification['ok'],
                'chain--broken' => $verification && ! $verification['ok'],
                'chain--unknown' => ! $verification,
            ])>
                @if ($verifying)
                    {{-- Doğrulama kuyrukta: sonuç geldiğinde bu şerit kendini günceller. --}}
                    <span><strong>Doğrulanıyor…</strong>
                        <span class="text-sm text-muted">Sonuç bittiğinde burada görünür; sayfayı yenileyin.</span></span>
                @elseif (! $verification)
                    <span class="text-muted">Zincir henüz doğrulanmadı.</span>
                @elseif ($verification['ok'])
                    <span><strong>Zincir bütün.</strong>
                        {{ $verification['checked'] }} satır doğrulandı.</span>
                @else
                    <span><strong>Zincir KIRIK.</strong>
                        @if ($verification['broken_id'] !== null && empty($verification['detail']))
                            İlk bozuk satır: #{{ $verification['broken_id'] }}.
                        @else
                            {{ $verification['detail'] ?? 'Doğrulama başarısız.' }}
                        @endif
                    </span>
                @endif

                @if ($verification && $verification['unprotected'] > 0)
                    {{--
                        Zincirden önce yazılmış satırlar. Sonradan özetlenmezler:
                        değişmediklerine dair elimizde kanıt yok ve öyleymiş gibi
                        davranmak zincirin anlamını ortadan kaldırırdı.
                    --}}
                    <span class="text-sm text-muted">
                        {{ $verification['unprotected'] }} satır zincirden önce yazılmış, korumasız.
                    </span>
                @endif

                @if ($verification)
                    <span class="text-sm text-muted mono">
                        {{ \Illuminate\Support\Carbon::parse($verification['checked_at'])->translatedFormat('d.m.Y H:i') }}
                    </span>
                @endif

                <span class="spacer"></span>

                <form method="POST" data-inline action="{{ route('logs.verify') }}">
                    @csrf
                    <button type="submit" class="btn btn--sm" @disabled($verifying)>
                        {{ $verifying ? 'Doğrulanıyor…' : 'Zinciri doğrula' }}
                    </button>
                </form>
            </div>

            {{--
                Kayda daraltılmış görünüm. Süzgecin varlığı ekranda YAZAR ve
                kaldırma yolu bir tık uzaktadır; yoksa kullanıcı eksik bir
                listeye bakıp "kayıtlar kayboldu" sanır.
            --}}
            @if ($subject)
                <div class="filter-note" data-record-filter>
                    <span>Yalnızca <strong>{{ $subject->name }}</strong> kaydının geçmişi gösteriliyor.</span>
                    <span class="spacer"></span>
                    <a href="{{ route('logs.index', ['islem' => $action ?: null]) }}"
                       class="btn btn--sm">Süzgeci kaldır</a>
                </div>
            @endif

            <form method="GET" class="row row--wrap filter-bar">
                @if ($subject)
                    <input type="hidden" name="kayit" value="{{ $subject->uuid }}">
                @endif

                <x-ui.search name="q" :value="$query" placeholder="Özet, kişi veya IP"
                             label="Kayıt filtrele" :standalone="false" />

                <x-form.select name="islem" :options="$actions" :selected="$action" />

                <button type="submit" class="btn btn--sm">Filtrele</button>
            </form>

            <x-ui.panel flush>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Zaman</th>
                            <th>Kişi</th>
                            <th>İşlem</th>
                            <th>Özet</th>
                            <th>Gerekçe</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td data-label="Zaman" class="mono text-muted">{{ $log->created_at->translatedFormat('d.m.Y H:i:s') }}</td>
                                <td data-label="Kişi">{{ $log->actor_name }}</td>
                                <td data-label="İşlem"><x-ui.badge :variant="$log->action->badge()">{{ $log->action->label() }}</x-ui.badge></td>
                                <td data-label="Özet">
                                    <span class="log-summary">
                                    <span>{{ $log->summary }}</span>
                                    </span>
                                </td>
                                <td data-label="Gerekçe" class="text-muted">{{ $log->reason ?: '—' }}</td>
                                <td data-label="IP" class="mono text-muted">{{ $log->ip_address ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6"><x-ui.empty title="Kayıt yok" /></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-ui.panel>

            {{ $logs->links() }}
        </div>
    </div>
</x-layout.app>
