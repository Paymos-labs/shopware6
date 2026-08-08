<?php

declare(strict_types=1);

namespace PaymosPayments;

use Paymos\Client;
use PaymosPayments\Service\PaymosPaymentHandler;
use PaymosPayments\Service\PaymosPaymentHandler67;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

// Load the bundled Paymos PHP SDK. The dashboard ships the SDK inside the
// plugin's own vendor/ directory (Shopware does not run composer install for a
// plugin's runtime requires on ZIP upload), so register its autoloader here if
// Composer has not already done so. Guarded by class_exists to stay a no-op
// when the SDK is autoloaded through the project's composer.
if (!class_exists(Client::class, false)) {
    $paymosVendorAutoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($paymosVendorAutoload)) {
        require_once $paymosVendorAutoload;
    }
}

/**
 * Paymos hosted-checkout payment plugin for Shopware 6 (6.5, 6.6 and 6.7).
 *
 * Installs a single "Pay with crypto (Paymos)" payment method. Which handler it
 * binds to depends on the running core: 6.5/6.6 use the async handler interface,
 * 6.7 replaced it with AbstractPaymentHandler. The two APIs never coexist, so one
 * class cannot serve both — but one package can, because PHP loads a class only
 * when something references it and the container is only ever told about one of
 * them (see {@see build()}).
 */
class PaymosPayments extends Plugin
{
    /**
     * Stable technical name for the payment method (plugin-prefixed to avoid
     * collisions, per Shopware guidance).
     *
     * This — not the handler class — is how the plugin finds its own payment
     * method. The handler class name is version-dependent and changes under a
     * store when Shopware itself is upgraded; the technical name never does.
     */
    public const PAYMENT_METHOD_TECHNICAL_NAME = 'paymos_crypto';

    /**
     * Register the payment handler that fits the running Shopware version.
     *
     * Capability, not version arithmetic — but the capability to ask about is the
     * OLD interface, not the new class. Late 6.6 patches (verified on 6.6.9.0) ship
     * BOTH payment APIs, so keying on AbstractPaymentHandler would make the plugin
     * behave differently between two 6.6 stores. The async interface disappearing is
     * what actually marks 6.7, and it gives the whole 6.6 line one behaviour.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/Resources/config'));
        $loader->load(self::legacyPaymentApiIsGone() ? 'handler_67.xml' : 'handler_legacy.xml');
    }

    /**
     * The payment handler FQCN for the running Shopware version — the value
     * Shopware stores as the payment method's handlerIdentifier and resolves
     * against the registered handler services.
     */
    public static function paymentHandlerClass(): string
    {
        return self::legacyPaymentApiIsGone()
            ? PaymosPaymentHandler67::class
            : PaymosPaymentHandler::class;
    }

    private static function legacyPaymentApiIsGone(): bool
    {
        return !interface_exists(AsynchronousPaymentHandlerInterface::class);
    }

    public function install(InstallContext $installContext): void
    {
        $this->upsertPaymentMethod($installContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        // Re-run the upsert: on a store that was upgraded across a Shopware major
        // while the plugin sat installed, the stored handlerIdentifier still names
        // the class of the previous era and the payment method would resolve to
        // nothing. Activation is the one lifecycle hook a merchant reliably
        // triggers after such an upgrade.
        $this->upsertPaymentMethod($activateContext->getContext());
        $this->setPaymentMethodIsActive(true, $activateContext->getContext());
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->setPaymentMethodIsActive(false, $deactivateContext->getContext());
        parent::deactivate($deactivateContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Only deactivate. Removing the payment method would break historical
        // orders that referenced it (Shopware's own guidance). The plugin's
        // tables are likewise kept unless the merchant keeps user data — see
        // keepUserData().
        $this->setPaymentMethodIsActive(false, $uninstallContext->getContext());

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get('Doctrine\\DBAL\\Connection');
        $connection->executeStatement('DROP TABLE IF EXISTS `paymos_payment_event`');
        $connection->executeStatement('DROP TABLE IF EXISTS `paymos_payment_invoice`');
    }

    private function upsertPaymentMethod(Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($context);
        if ($paymentMethodId !== null) {
            // Already there — only make sure it points at the handler this
            // Shopware version can actually resolve. Everything else is the
            // merchant's to edit.
            $this->paymentMethodRepository()->update(
                [[
                    'id' => $paymentMethodId,
                    'handlerIdentifier' => self::paymentHandlerClass(),
                ]],
                $context
            );

            return;
        }

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(static::class, $context);

        $data = [
            // The handler is selected by this identifier (the handler FQCN).
            'handlerIdentifier' => self::paymentHandlerClass(),
            'technicalName' => self::PAYMENT_METHOD_TECHNICAL_NAME,
            'name' => 'Pay with crypto (Paymos)',
            'description' => 'Pay with USDT or USDC. You will be redirected to the secure Paymos checkout.',
            'pluginId' => $pluginId,
            // Keep the method usable after the order is created (e.g. payment
            // failed and the buyer wants to retry).
            'afterOrderEnabled' => true,
            'translations' => $this->installedTranslations($context),
        ];

        $this->paymentMethodRepository()->create([$data], $context);
    }

    /**
     * The checkout label and blurb per locale. English is also the untranslated
     * fallback above, so a shop running a language we have no copy for still
     * reads as a finished product rather than a technical name.
     *
     * @return array<string, array{name: string, description: string}>
     */
    private static function paymentMethodCopy(): array
    {
        return [
            'en-GB' => [
                'name' => 'Pay with crypto (Paymos)',
                'description' => 'Pay with USDT or USDC. You will be redirected to the secure Paymos checkout.',
            ],
            'de-DE' => [
                'name' => 'Mit Krypto bezahlen (Paymos)',
                'description' => 'Bezahlen Sie mit USDT oder USDC. Sie werden zur sicheren Paymos-Kasse weitergeleitet.',
            ],
            'ru-RU' => [
                'name' => 'Оплата криптовалютой (Paymos)',
                'description' => 'Оплата в USDT или USDC. Вы перейдёте на защищённую страницу оплаты Paymos.',
            ],
            'es-ES' => [
                'name' => 'Pagar con cripto (Paymos)',
                'description' => 'Paga con USDT o USDC. Te llevaremos a la pasarela segura de Paymos.',
            ],
            'tr-TR' => [
                'name' => 'Kripto ile öde (Paymos)',
                'description' => 'USDT veya USDC ile ödeyin. Güvenli Paymos ödeme sayfasına yönlendirileceksiniz.',
            ],
            'zh-CN' => [
                'name' => '加密货币支付 (Paymos)',
                'description' => '使用 USDT 或 USDC 付款。我们会将您转到 Paymos 安全收银台。',
            ],
        ];
    }

    /**
     * Only the locales this shop actually has a language for. Writing a
     * translation for an absent locale is not something the merchant can fix,
     * so the copy is filtered against the language table rather than sent
     * hopefully — a failed install is a far worse outcome than an English label.
     *
     * @return array<string, array{name: string, description: string}>
     */
    private function installedTranslations(Context $context): array
    {
        $copy = self::paymentMethodCopy();

        $criteria = new Criteria();
        $criteria->addAssociation('translationCode');

        /** @var EntityRepository $languageRepository */
        $languageRepository = $this->container->get('language.repository');
        $languages = $languageRepository->search($criteria, $context);

        $available = [];
        foreach ($languages as $language) {
            $code = $language->getTranslationCode();
            if ($code !== null && isset($copy[$code->getCode()])) {
                $available[$code->getCode()] = $copy[$code->getCode()];
            }
        }

        // A shop with no matching language at all still gets the English row,
        // which is the same text the untranslated columns already carry.
        return $available === [] ? ['en-GB' => $copy['en-GB']] : $available;
    }

    private function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($context);
        if ($paymentMethodId === null) {
            return;
        }

        $this->paymentMethodRepository()->update(
            [['id' => $paymentMethodId, 'active' => $active]],
            $context
        );
    }

    private function getPaymentMethodId(Context $context): ?string
    {
        // Keyed on the technical name, never the handler class: the class is
        // version-dependent, so looking the method up by it would lose track of
        // the plugin's own payment method the moment the store changes majors.
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('technicalName', self::PAYMENT_METHOD_TECHNICAL_NAME))
            ->setLimit(1);

        return $this->paymentMethodRepository()->searchIds($criteria, $context)->firstId();
    }

    private function paymentMethodRepository(): EntityRepository
    {
        /** @var EntityRepository $repository */
        $repository = $this->container->get('payment_method.repository');

        return $repository;
    }
}
