{{-- Boş pay ("%62 boşta"); hiç boş yoksa "Dolu" kırmızı (bkz. Ipv4BlockPlan::freeShare). --}}
<span @class(['free-share--full' => $share['full']])>{{ $share['label'] }}</span>