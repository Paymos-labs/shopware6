# Paymos Shopware 6 支付插件

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Paymos 官方 Shopware 6 稳定币支付插件。安装后会新增一种支付方式——**Pay with crypto (Paymos)**——买家结账时跳转至 Paymos 托管收银台，自行选择 USDT/USDC 等稳定币和链网络，并从个人钱包发起转账。链上确认后，订单交易仅凭带 HMAC-SHA256 签名验证的 webhook 推进至 `paid`，绝不以买家是否返回店面作为判断依据。

[![观看视频：12 款官方 CMS 稳定币支付插件 — Paymos 一键连接](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **视频演示（1:12，内置 6 种语言字幕）：** [在 YouTube 上观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

声明于 `PaymosPayments/composer.json`：

- `shopware/core`：`~6.5.7 || ~6.6.0 || ~6.7.0` —— 支持 Shopware 6.5.7 及后续 6.5 补丁版本、整个 6.6 系列以及 6.7
- `php`：`>=8.2`
- HTTPS 独立站店面；一键连接流程会拒绝纯 HTTP 的基础 URL
- 一个 Paymos 账户；控制台应已处于待绑定项目页面

发布压缩包已在插件内部内置 Paymos PHP SDK，因为 Shopware 通过 ZIP 上传安装插件时不会为插件自身运行 Composer。压缩包同时附带了 Shopware 所需的两套预编译 Administration 管理端产物——适用于 6.5 与 6.6 的 webpack 版本以及适用于 6.7 的 Vite 版本——安装后无需再执行管理端构建步骤。

## 安装

从 [Releases](https://github.com/Paymos-labs/shopware6/releases/latest) 或 Paymos 控制台的 **CMS integration（CMS 集成）** 面板下载 `paymos-shopware6-<version>.zip`。其根目录名称为 `PaymosPayments/`，符合 Shopware 要求的技术目录规范。

通过管理后台安装：进入 **Extensions → My extensions → Upload extension**，选择压缩包，然后安装并激活。

通过命令行安装（先将压缩包解压至 `custom/plugins/`）：

```bash
bin/console plugin:refresh
bin/console plugin:install --activate PaymosPayments
bin/console cache:clear
```

激活插件后会自动创建并启用该支付方式。请前往 **Sales Channels → 你的销售渠道 → Payment methods**，将其添加到每一个需要提供该收款方式的销售渠道中。订单创建后该方式仍保持可选，因此支付失败的买家可以直接从订单页重试，而无需重新下单。

公开发布的压缩包对所有商户完全一致，不含任何 API 密钥、API secret、项目 ID、webhook secret、OAuth token 或 device code。仅安装插件不会建立任何连接。

## 一键连接

插件在管理后台注册了专属 Administration 模块，连接操作位于该模块中，而非扩展配置卡片内。

1. 在 Paymos 控制台选中该店铺用于收款的项目。当前打开的项目即为绑定的项目；插件内不提供第二处项目选择。
2. 在 Shopware 中进入 **Settings → Plugins → Paymos**，点击 **Connect Paymos**。
3. 在弹出的标签页中批准显示的店铺 URL 和项目。若浏览器拦截了弹窗也不影响继续——页面会直接显示授权链接和验证码（user code）。
4. 等待页面出现 `Paymos connected.`。

一次批准同时覆盖 Sandbox 和 Live 两套环境。系统会复用你唯一的活跃 Payment 密钥（若尚不存在则自动创建），并在以下地址注册 Invoice webhook：

```text
https://your-store.example/paymos/webhook
```

仅当回调 URL、类别和项目全部一致时才会复用现有 webhook；该地址上的已有冲突配置绝不会被静默覆盖。

凭证采用 AES-256-GCM 封装存储在 `SystemConfigService` 中，加密密钥派生自当前安装的 `kernel.secret`，绝不会返回给浏览器。界面中不存在手动输入 secret 的字段。

## 按销售渠道配置

插件的配置卡片按 sales channel ID 读取，因此以下两个字段支持按销售渠道独立设置：

| 字段 | 默认值 | 效果 |
|---|---|---|
| Mode | `sandbox` | 该销售渠道结账时使用的凭证环境 |
| Debug logging | off | 将常规 webhook 诊断信息写入 Shopware 日志；运行错误无论开关与否都会记录 |

可以让一个销售渠道留在 Sandbox 中测试，同时让另一个渠道已经开始接收 Live 真实收款。凭证本身按整套 Shopware 安装共享，因此只需连接一次，之后按渠道单独切换 **Mode** 即可。Webhook 端点不属于单个渠道，读取的是全局配置值。

## 订单交易状态

| Paymos 事件 | 订单交易状态 |
|---|---|
| 买家跳转至托管收银台 | 保持 `open` |
| `invoice.confirming` | `open`（若此前已离开该状态则重新打开） |
| `invoice.underpaid_waiting` | `open`；买家仍需补足剩余金额 |
| `invoice.awaiting_payment` | `open`；此前计入的一笔付款因链上重组而失效 |
| `invoice.paid`、`invoice.paid_over` | `paid` |
| `invoice.underpaid` | `failed` |
| `invoice.expired`、`invoice.cancelled` | `cancelled` |

Shopware 状态机不允许在两个终态之间直接切换，插件也绝不会发起此类非法迁移。已处于 `cancelled` 的交易不会被迟到的 `underpaid` 改动；而已处于 `cancelled` 或 `failed` 却实际完成支付的交易会先重新打开再置为 `paid`。任何事件都不会降级已经到达 `paid`、`refunded` 或 `refunded_partially` 的交易。

当买家从托管收银台返回店面时，支付处理器会读取当前交易状态而非 URL 参数。如果 webhook 尚未到达，交易保持原状——此处抛出异常会把买家送进错误页，并误取消一笔即将确认的付款。付款完成后再点击取消链接也不会撤销已完成的付款。

## 上线前测试

1. 将待测试销售渠道的 **Mode** 保持为 Sandbox，并下一笔测试订单。
2. 在仍处于 Sandbox 的 Paymos 控制台打开对应账单，点击 **Pay Full**、**Pay 50%**、**Pay 150%** 或 **Cancel**。每个操作都会发送真实生命周期事件，不会产生实际资金流动。
3. 查看订单的交易状态，并在开启 Debug logging 时检查 Shopware 日志。
4. 将该销售渠道的 **Mode** 切换为 Live。Live 凭证已随同一次一键连接下发，无需重新连接。

## Webhook 与故障恢复

`POST /paymos/webhook` 是前台店面路由，因此回调会发送到店面域名。校验依据是 `X-Webhook-Signature` 请求头——对 `{timestamp}.{body}` 计算十六进制 HMAC-SHA256——签名无效或时间戳过期将返回 `401`。`X-Webhook-Id` 在重试期间保持稳定，重复投递会直接确认而不改动订单。在任何终态事件生效前，插件都会向 Merchant API 回查账单并与本地保存的快照比对。

一次投递周期包含约 16 小时内的 11 次尝试。对于超出该窗口的极端情况，插件提供了一条控制台命令：

```bash
bin/console paymos:reconcile
```

该命令会重新拉取未结账单与处理中的退款，并通过与 webhook 相同的受保护路径重新应用状态。建议在正式店铺上配置 cron 每隔几分钟执行一次。

## 退款

插件通过 Paymos Merchant API（`/v1/refunds`）支持两种原生退款模式：

- `full` —— 全额退还已付账单金额；
- `overpayment` —— 仅退还 `invoice.paid_over` 交易中多付的超额部分，同时保持订单为已付款状态。

资金仅退回 Paymos 在账单付款时捕获并验证的 `first_sender` 钱包地址。不支持手动输入收款钱包地址，插件的退款守卫也会拦截任意部分退款，以及针对 Paymos 订单交易的未经核验的手动退款状态修改。

在 Shopware Administration 后台，打开 **Settings → Plugins → Paymos**，在 **Paymos refunds** 卡片中输入 **Order transaction ID**，即可使用 **Full refund**（`full`）、**Refund overpayment**（`overpayment`）或 **Check status**。只有在 Paymos 通过 `POST /paymos/webhook` 或 `bin/console paymos:reconcile` 确认退款达到 `completed` 后，Shopware 才会将订单交易切换为 `refunded`（`full` 模式）或记录超额退款备注。请通过 cron 每隔几分钟运行一次该命令以追回遗漏的 webhook。**Check status** 仅用于展示当前状态，不会执行对账或应用退款。在 Sandbox 模式下，无需真实资金划转即可模拟退款终态（`completed`、`failed`、`cancelled`）。

## 升级 Shopware

Shopware 6.5 与 6.6 使用异步支付处理器接口，而 6.7 将其替换为 `AbstractPaymentHandler`。同一个发布压缩包内置了两套实现，并会自动绑定当前运行内核所暴露的 API；不过支付方式上记录的处理器类名是在安装时写入数据库的。跨主版本升级 Shopware 后，请停用并重新激活本插件：重新激活会重写该处理器记录，使支付方式恢复正常解析。插件始终通过技术名称 `paymos_crypto` 定位该支付方式，该名称在不同版本间保持不变。

## 故障排查

**Settings 下看不到 Paymos 入口。** Administration 模块来自插件预编译的静态资源。请运行 `bin/console assets:install` 和 `bin/console cache:clear`，然后强制刷新管理后台页面。

**结账页缺少该支付方式。** 该方式已经存在且处于激活状态，但 Shopware 仅展示销售渠道列表中所选的支付方式。请将其添加到对应渠道的支付方式列表中，并检查是否有规则排除了当前测试购物车。

**一键连接因店铺 URL 报错。** 仅接受 `https://` 地址，且该 URL 由 Shopware 自身接收到的请求构建。如果在反向代理层终结 TLS，必须通过 Shopware 的 trusted-proxy 配置转发协议头，否则 Shopware 仍会报告 `http://` 从而导致授权被拒绝。

## 相关链接

- 文档：[paymos.io/zh-hans/docs/cms-shopware6](https://paymos.io/zh-hans/docs/cms-shopware6)
- 源码与发布：[Paymos-labs/shopware6](https://github.com/Paymos-labs/shopware6)
- 技术支持：[support@paymos.io](mailto:support@paymos.io)
