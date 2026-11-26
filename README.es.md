# Paymos para Shopware 6

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Plugin oficial de pagos de Paymos para Shopware 6. Al instalarlo añade un único método de pago —
**Pay with crypto (Paymos)** — que dirige al comprador a la página de pago alojada de Paymos, donde
elige una stablecoin y una red y paga desde su propia billetera. La transacción del pedido alcanza
`paid` mediante un webhook firmado una vez que la transferencia se confirma en la cadena. El regreso
del comprador a la tienda nunca decide el estado.

[![Ver vídeo: 12 plugins oficiales de pagos cripto para CMS — conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Vídeo demostrativo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

Declarados en `PaymosPayments/composer.json`:

- `shopware/core`: `~6.5.7 || ~6.6.0 || ~6.7.0` — Shopware 6.5.7 y parches posteriores de 6.5, toda
  la rama 6.6 y 6.7
- `php`: `>=8.2`
- Una tienda sobre HTTPS; la conexión se rechaza en una URL base HTTP sin cifrar
- Una cuenta de Paymos; el panel debe estar abierto en el proyecto que vas a vincular

El archivo incluye el SDK de Paymos para PHP dentro del propio plugin, ya que Shopware no ejecuta
Composer para las dependencias de un plugin al subir un ZIP. También trae el módulo de
Administration compilado en ambos formatos que busca Shopware — webpack para 6.5 y 6.6, Vite para
6.7 — así que no hace falta ningún paso de compilación del panel.

## Instalación

Descarga `paymos-shopware6-<version>.zip` desde
[Releases](https://github.com/Paymos-labs/shopware6/releases/latest) o desde el panel **CMS
integration** en el panel de Paymos. Su directorio raíz es `PaymosPayments/`, el nombre técnico
que espera Shopware.

Desde Administration: **Extensions → My extensions → Upload extension**, elige el archivo y luego
instálalo y actívalo.

Desde la shell, con el archivo descomprimido en `custom/plugins/`:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate PaymosPayments
bin/console cache:clear
```

La activación crea el método de pago y lo habilita. Añádelo a cada canal de venta donde deba
ofrecerse desde **Sales Channels → tu canal → Payment methods**. El método sigue siendo
seleccionable una vez creado el pedido, de modo que un comprador cuyo pago falló puede reintentarlo
desde el pedido en lugar de empezar de cero.

Los archivos publicados son idénticos para todos los comercios y no contienen clave API, secreto
API, ID de proyecto, secreto de webhook, token OAuth ni código de dispositivo. Instalarlo no
conecta nada.

## Conexión

El plugin registra su propio módulo en Administration y la conexión se realiza allí en lugar de la
tarjeta de configuración de la extensión.

1. Selecciona en el panel de Paymos el proyecto con el que debe facturar la tienda. El proyecto que
   esté abierto allí es el que quedará vinculado; el plugin no ofrece una segunda selección.
2. En Shopware ve a **Settings → Plugins → Paymos** y pulsa **Connect Paymos**.
3. Aprueba la URL de la tienda y el proyecto en la pestaña que se abre. Una pestaña bloqueada no es
   un callejón sin salida: la página muestra en su lugar el enlace de aprobación y el código de usuario.
4. Espera a que aparezca `Paymos connected.`

Una sola aprobación cubre Sandbox y Live. Se reutiliza tu única clave Payment activa —o se crea una
si no existe— y se registra un webhook Invoice en:

```text
https://your-store.example/paymos/webhook
```

Un webhook existente solo se reutiliza si coinciden su URL de callback, categoría y proyecto; nada
en esa dirección se sobrescribe en silencio.

Las credenciales se sellan en un sobre AES-256-GCM dentro de `SystemConfigService`, con clave
derivada del `kernel.secret` de la instalación, y nunca se devuelven al navegador. No existe ningún
campo para teclear un secreto.

## Por canal de venta

La tarjeta de configuración del plugin se lee con el ID del canal de venta, por lo que sus dos
campos son por canal:

| Campo | Valor predeterminado | Efecto |
|---|---|---|
| Mode | `sandbox` | Qué conjunto de credenciales utiliza el checkout de ese canal |
| Debug logging | off | Diagnósticos rutinarios de webhooks en el log de Shopware; los errores operativos se registran siempre |

Un canal puede seguir probando en Sandbox mientras otro ya acepta pagos reales en Live. Las
credenciales en sí son globales para toda la instalación: conecta una vez y mueve cada canal solo
con **Mode**. El endpoint de webhook no pertenece a un canal concreto y lee el valor global.

## Estados de transacción del pedido

| Evento de Paymos | Transacción del pedido |
|---|---|
| Comprador redirigido a la página de pago alojada | permanece en `open` |
| `invoice.confirming` | `open`, reabierta si había salido de ese estado |
| `invoice.underpaid_waiting` | `open`; el comprador aún debe el importe restante |
| `invoice.awaiting_payment` | `open`; un reorg eliminó un pago que ya se había contado |
| `invoice.paid`, `invoice.paid_over` | `paid` |
| `invoice.underpaid` | `failed` |
| `invoice.expired`, `invoice.cancelled` | `cancelled` |

La máquina de estados de Shopware no tiene transición entre dos estados finales, y el plugin nunca
solicita una. Una transacción que ya está en `cancelled` no se toca ante un `underpaid` posterior;
una transacción `cancelled` o `failed` que resulta haberse pagado se reabre primero y después pasa
a `paid`. Nada rebaja una transacción que haya llegado a `paid`, `refunded` o `refunded_partially`.

Cuando el comprador vuelve desde la página de pago alojada, el manejador lee la transacción en
lugar de la URL. Si el webhook todavía no ha llegado, la transacción se deja tal como está: lanzar
un error allí enviaría al comprador a la página de error y cancelaría un pago a punto de
confirmarse. Pulsar un enlace de cancelación cuando el pago ya se completó no lo deshace.

## Probar antes de pasar a producción

1. Deja **Mode** en Sandbox para el canal que estás probando y haz un pedido.
2. Abre esa factura en el panel de Paymos, aún en Sandbox, y pulsa **Pay Full**, **Pay 50%**,
   **Pay 150%** o **Cancel**. Cada acción emite los eventos reales del ciclo de vida sin mover fondos.
3. Revisa el estado de transacción del pedido y el log de Shopware con Debug logging activado.
4. Cambia **Mode** de ese canal a Live. Las credenciales Live llegaron con la misma aprobación, así
   que no hace falta reconectar nada.

## Webhooks y recuperación

`POST /paymos/webhook` es una ruta de storefront, por lo que las entregas llegan al dominio de la
tienda. La verificación se basa en la cabecera `X-Webhook-Signature` — HMAC-SHA256 hexadecimal
sobre `{timestamp}.{body}` — y una firma incorrecta o una marca de tiempo caducada responde `401`.
`X-Webhook-Id` es estable entre reintentos, así que una repetición se confirma sin tocar el pedido.
Antes de que un evento final modifique nada, la factura se vuelve a leer desde la Merchant API y se
compara con la instantánea guardada.

Un ciclo de entrega ejecuta 11 intentos a lo largo de unas 16 horas. Para cualquier caso que supere
esa ventana, el plugin incluye un comando de consola:

```bash
bin/console paymos:reconcile
```

Vuelve a consultar facturas abiertas y reembolsos pendientes y los reaplica a través de las mismas
rutas protegidas que usa el webhook. Ejecútalo desde cron cada pocos minutos en una tienda en
producción.

## Reembolsos

El plugin admite reembolsos nativos a través de la Merchant API de Paymos (`/v1/refunds`) en dos
modos:

- `full` — reembolsa el importe íntegro de la factura pagada;
- `overpayment` — reembolsa únicamente el exceso recibido en una transacción `invoice.paid_over`
  manteniendo el pedido como pagado.

Los fondos regresan exclusivamente a la dirección de billetera `first_sender` verificada que Paymos
capturó al pagarse la factura. No se introduce manualmente ninguna dirección destinataria, y la
protección de reembolsos del plugin bloquea tanto los reembolsos parciales arbitrarios como las
transiciones manuales no verificadas de estado de reembolso en transacciones de pedido de Paymos.

En Shopware Administration abre **Settings → Plugins → Paymos**, introduce el **Order transaction
ID** en la tarjeta **Paymos refunds** y usa **Full refund** (`full`), **Refund overpayment**
(`overpayment`) o **Check status**. Shopware pasa la transacción del pedido a `refunded` (para
`full`) o registra la nota de reembolso por exceso únicamente después de que Paymos confirme
`completed` mediante `POST /paymos/webhook` o `bin/console paymos:reconcile`. Ejecuta el comando
por cron cada pocos minutos para recuperar webhooks perdidos. **Check status** solo muestra el
estado actual; no concilia ni aplica reembolsos. En modo Sandbox pueden simularse resultados
finales de reembolso (`completed`, `failed`, `cancelled`) sin mover fondos.

## Actualizar Shopware

6.5 y 6.6 utilizan la interfaz asíncrona de manejador de pagos; 6.7 la sustituyó por
`AbstractPaymentHandler`. Un mismo archivo incluye ambas y enlaza la API que exponga el núcleo en
ejecución, pero el manejador guardado en el método de pago se escribe en el momento de la
instalación. Después de subir una tienda de versión principal, desactiva y vuelve a activar el
plugin: la activación reescribe ese manejador para que el método vuelva a resolverse. El método se
localiza siempre por su nombre técnico `paymos_crypto`, que no cambia entre versiones.

## Resolución de problemas

**No aparece ninguna entrada de Paymos en Settings.** El módulo de Administration proviene de los
activos compilados del plugin. Ejecuta `bin/console assets:install` y `bin/console cache:clear`, y
luego recarga el panel con una actualización forzada.

**El método no aparece en el checkout.** Existe y está activo, pero Shopware solo ofrece los
métodos de pago listados en un canal de venta. Añádelo a los métodos de pago de ese canal y
comprueba que ninguna regla lo excluya para el carrito con el que estás probando.

**La conexión falla por la URL de la tienda.** Solo se acepta `https://`, y la URL se construye a
partir de la petición que ve el propio Shopware. Un proxy inverso que termine TLS debe reenviar el
esquema mediante la configuración trusted-proxy de Shopware; de lo contrario Shopware seguirá
informando `http://` y la aprobación será rechazada.

## Enlaces

- Documentación: [paymos.io/es/docs/cms-shopware6](https://paymos.io/es/docs/cms-shopware6)
- Código fuente y lanzamientos: [Paymos-labs/shopware6](https://github.com/Paymos-labs/shopware6)
- Soporte: [support@paymos.io](mailto:support@paymos.io)
