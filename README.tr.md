# Shopware 6 için Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

Shopware 6 için resmî Paymos ödeme eklentisi. Kurulduğunda tek bir ödeme yöntemi ekler — **Pay with
crypto (Paymos)** — ve alıcıyı bir stablecoin ile ağ seçip kendi cüzdanından ödeme yapabileceği
Paymos’un barındırılan ödeme sayfasına yönlendirir. Sipariş işlemi, transfer zincir üzerinde
onaylandığında imzalı bir webhook ile `paid` durumuna ulaşır. Alıcının mağazaya geri dönmesi buna
asla karar vermez.

[![İzleyin: 12 Resmî CMS Kripto Ödeme Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video anlatım (1:12, 6 dilde altyazılı):** [YouTube’da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

`PaymosPayments/composer.json` dosyasında tanımlıdır:

- `shopware/core`: `~6.5.7 || ~6.6.0 || ~6.7.0` — Shopware 6.5.7 ve sonraki 6.5 yamaları, 6.6
  serisinin tamamı ve 6.7
- `php`: `>=8.2`
- HTTPS üzerinde çalışan bir vitrin; düz HTTP temel URL ile bağlantı reddedilir
- Bir Paymos hesabı; panelde bağlamak istediğiniz proje açık olmalıdır

Shopware bir ZIP yüklemesinde eklentinin kendi bağımlılıkları için Composer çalıştırmadığından,
arşiv Paymos PHP SDK’yı eklentinin içinde paketlenmiş olarak sunar. Ayrıca Shopware’in aradığı her
iki düzende — 6.5 ve 6.6 için webpack, 6.7 için Vite — önceden derlenmiş Administration modülünü
içerir; bu yüzden yönetim derleme adımı gerekmez.

## Kurulum

[Releases](https://github.com/Paymos-labs/shopware6/releases/latest) sayfasından veya Paymos
panelindeki **CMS integration** bölümünden `paymos-shopware6-<version>.zip` dosyasını indirin. Kök
klasörü, Shopware’in beklediği teknik klasör adı olan `PaymosPayments/` şeklindedir.

Administration üzerinden: **Extensions → My extensions → Upload extension** yolunu açın, dosyayı
seçip kurun ve etkinleştirin.

Kabuk üzerinden, arşivi `custom/plugins/` dizinine çıkardıktan sonra:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate PaymosPayments
bin/console cache:clear
```

Etkinleştirme ödeme yöntemini oluşturur ve açar. Sunulmasını istediğiniz her satış kanalına
**Sales Channels → kanalınız → Payment methods** altından ekleyin. Sipariş oluşturulduktan sonra da
yöntem seçilebilir kalır; böylece ödemesi başarısız olan bir alıcı baştan başlamak yerine sipariş
üzerinden yeniden deneyebilir.

Yayımlanan arşivler her işyeri için birebir aynıdır; API anahtarı, API secret’ı, proje ID’si,
webhook secret’ı, OAuth token’ı veya cihaz kodu içermez. Kurulum yapmak tek başına bir bağlantı
kurmaz.

## Bağlantı

Eklenti kendi Administration modülünü kaydeder ve bağlantı işlemi uzantının yapılandırma kartı
yerine bu modülde yer alır.

1. Paymos panelinde mağazanın faturalandıracağı projeyi seçin. Orada hangi proje açıksa o bağlanır;
   eklenti ikinci bir seçim sunmaz.
2. Shopware’de **Settings → Plugins → Paymos** ekranına gidin ve **Connect Paymos** düğmesine basın.
3. Açılan sekmede mağaza URL’sini ve projeyi onaylayın. Engellenen bir sekme çıkmaz sokak değildir —
   sayfa bu durumda onay bağlantısını ve kullanıcı kodunu doğrudan gösterir.
4. `Paymos connected.` ifadesinin görünmesini bekleyin.

Tek bir onay Sandbox ve Live ortamlarının ikisini de kapsar. Tek etkin Payment anahtarınız yeniden
kullanılır (yoksa yeni bir tane oluşturulur) ve aşağıdaki adrese bir Invoice webhook’u kaydedilir:

```text
https://your-store.example/paymos/webhook
```

Mevcut bir webhook yalnızca callback URL’si, kategori ve proje tamamen eşleştiğinde yeniden
kullanılır; o adresteki hiçbir kayıt sessizce üzerine yazılmaz.

Kimlik bilgileri, kurulumun `kernel.secret` değerinden türetilen anahtarla `SystemConfigService`
içinde AES-256-GCM zarfı olarak mühürlenir ve tarayıcıya asla geri gönderilmez. Elle secret
yazılabilecek bir alan yoktur.

## Satış kanalı başına ayarlar

Eklentinin yapılandırma kartı satış kanalı ID’si ile okunur; dolayısıyla iki alanı kanal bazlıdır:

| Alan | Varsayılan | Etki |
|---|---|---|
| Mode | `sandbox` | İlgili kanalın ödeme adımında hangi kimlik bilgisi setini kullandığı |
| Debug logging | off | Rutin webhook tanılama kayıtlarını Shopware günlüğüne yazar; operasyonel hatalar her koşulda kaydedilir |

Bir kanal Sandbox’ta test yapmaya devam ederken bir diğeri Live ödemeleri alabilir. Kimlik
bilgilerinin kendisi kurulum genelindedir; bir kez bağlanın ve her kanalı yalnızca **Mode** ile
taşıyın. Webhook uç noktası tek bir kanala ait değildir ve genel değeri okur.

## Sipariş işlem durumları

| Paymos olayı | Sipariş işlemi |
|---|---|
| Alıcı barındırılan ödeme sayfasına yönlendirildi | `open` olarak kalır |
| `invoice.confirming` | `open` (bu durumdan çıkmışsa yeniden açılır) |
| `invoice.underpaid_waiting` | `open`; alıcı hâlâ kalan tutarı borçludur |
| `invoice.awaiting_payment` | `open`; bir reorg sayılmış ödemeyi geri aldı |
| `invoice.paid`, `invoice.paid_over` | `paid` |
| `invoice.underpaid` | `failed` |
| `invoice.expired`, `invoice.cancelled` | `cancelled` |

Shopware’in durum makinesi iki nihai durum arasında geçiş içermez ve eklenti de bunu asla istemez.
Zaten `cancelled` olan bir işlem sonradan gelen `underpaid` olayından etkilenmez; ödenmiş olduğu
anlaşılan `cancelled` veya `failed` bir işlem ise önce yeniden açılır, sonra `paid` yapılır.
`paid`, `refunded` veya `refunded_partially` durumuna ulaşmış bir işlemi hiçbir şey geriye almaz.

Alıcı barındırılan ödeme sayfasından döndüğünde işleyici URL yerine işlemi okur. Webhook henüz
ulaşmadıysa işlem olduğu gibi bırakılır — burada hata fırlatmak alıcıyı hata sayfasına gönderip
onaylanmak üzere olan bir ödemeyi iptal ederdi. Ödeme tamamlandıktan sonra tıklanan iptal bağlantısı
ödemeyi geri almaz.

## Canlıya geçmeden önce test edin

1. Test ettiğiniz kanal için **Mode** ayarını Sandbox’ta bırakın ve bir sipariş verin.
2. Paymos paneli hâlâ Sandbox’tayken o faturayı açın ve **Pay Full**, **Pay 50%**, **Pay 150%**
   veya **Cancel** düğmesine basın. Her biri gerçek yaşam döngüsü olaylarını üretir ve fon hareketi
   oluşturmaz.
3. Siparişin işlem durumunu ve Debug logging açıkken Shopware günlüğünü kontrol edin.
4. O kanalın **Mode** ayarını Live yapın. Live kimlik bilgileri aynı onayla geldiğinden yeniden
   bağlanmaya gerek yoktur.

## Webhook’lar ve kurtarma

`POST /paymos/webhook` bir vitrin rotasıdır, bu nedenle teslimatlar vitrin alan adına gelir.
Doğrulama `X-Webhook-Signature` başlığıyla — `{timestamp}.{body}` üzerinden onaltılık HMAC-SHA256 —
yapılır; geçersiz imza veya eski zaman damgası `401` döndürür. `X-Webhook-Id` yeniden denemeler
boyunca sabit kalır, dolayısıyla tekrar teslimat siparişe dokunmadan onaylanır. Nihai bir olay
herhangi bir değişiklik yapmadan önce fatura Merchant API’den geri okunur ve saklanan anlık
görüntüyle karşılaştırılır.

Bir teslimat döngüsü yaklaşık 16 saat boyunca 11 deneme çalıştırır. Bunun ötesine geçen durumlar
için eklenti bir konsol komutu içerir:

```bash
bin/console paymos:reconcile
```

Açık faturaları ve bekleyen iadeleri yeniden çeker ve webhook’un kullandığı aynı korumalı yollarla
yeniden uygular. Canlı bir mağazada birkaç dakikada bir cron ile çalıştırın.

## İadeler

Eklenti, Paymos Merchant API (`/v1/refunds`) üzerinden iki modda yerel iade desteği sunar:

- `full` — ödenmiş fatura tutarının tamamını iade eder;
- `overpayment` — siparişi ödenmiş tutarken `invoice.paid_over` işleminde yalnızca fazla ödenen
  tutarı iade eder.

Fonlar yalnızca Paymos’un fatura ödenirken yakaladığı doğrulanmış `first_sender` cüzdan adresine
döner. Elle alıcı cüzdan adresi girilmez ve Paymos sipariş işlemlerinde keyfî kısmi iadeler ile
doğrulanmamış manuel iade durum geçişleri eklentinin iade koruması tarafından engellenir.

Shopware Administration’da **Settings → Plugins → Paymos** ekranını açın, **Paymos refunds**
kartına **Order transaction ID** değerini girin ve **Full refund** (`full`), **Refund overpayment**
(`overpayment`) veya **Check status** seçeneğini kullanın. Shopware, sipariş işlemini yalnızca
Paymos `POST /paymos/webhook` veya `bin/console paymos:reconcile` üzerinden `completed` durumunu
onayladıktan sonra `refunded` durumuna geçirir (`full` için) ya da fazla ödeme iadesi notunu
kaydeder. Kaçırılan webhook’ları yakalamak için komutu cron ile birkaç dakikada bir çalıştırın.
**Check status** yalnızca güncel durumu gösterir; mutabakat yapmaz veya iade uygulamaz. Sandbox
modunda fon hareketi olmadan nihai iade sonuçları (`completed`, `failed`, `cancelled`) simüle
edilebilir.

## Shopware yükseltme

6.5 ve 6.6 asenkron ödeme işleyici arayüzünü kullanır; 6.7 bunu `AbstractPaymentHandler` ile
değiştirdi. Tek arşiv her ikisini de taşır ve çalışan çekirdeğin sunduğu API’yi bağlar, ancak ödeme
yönteminde kayıtlı işleyici kurulum anında yazılır. Bir mağazayı ana sürüm atlatarak yükselttikten
sonra eklentiyi devre dışı bırakıp yeniden etkinleştirin: etkinleştirme bu işleyiciyi yeniden yazar
ve yöntem tekrar çözümlenir. Yöntem her zaman sürümler arasında değişmeyen `paymos_crypto` teknik
adıyla bulunur.

## Sorun giderme

**Settings altında Paymos öğesi yok.** Administration modülü eklentinin derlenmiş varlıklarından
gelir. `bin/console assets:install` ve `bin/console cache:clear` komutlarını çalıştırın, ardından
yönetim panelini tam yenileme (hard refresh) ile yeniden yükleyin.

**Ödeme adımında yöntem görünmüyor.** Yöntem mevcut ve etkindir, ancak Shopware yalnızca satış
kanalının listelediği ödeme yöntemlerini sunar. Yöntemi ilgili kanalın ödeme yöntemlerine ekleyin
ve test ettiğiniz sepet için herhangi bir kuralın onu dışlamadığını kontrol edin.

**Bağlantı mağaza URL’sinde başarısız oluyor.** Yalnızca `https://` kabul edilir ve URL,
Shopware’in kendi gördüğü istekten oluşturulur. TLS’i sonlandıran bir ters proxy, şemayı
Shopware’in trusted-proxy yapılandırması üzerinden iletmelidir; aksi halde Shopware hâlâ `http://`
bildirir ve onay reddedilir.

## Bağlantılar

- Dokümantasyon: [paymos.io/tr/docs/cms-shopware6](https://paymos.io/tr/docs/cms-shopware6)
- Kaynak kodu ve sürümler: [Paymos-labs/shopware6](https://github.com/Paymos-labs/shopware6)
- Destek: [support@paymos.io](mailto:support@paymos.io)
