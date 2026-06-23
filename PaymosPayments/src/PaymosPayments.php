<?php

declare(strict_types=1);

namespace PaymosPayments;

use Paymos\Client;
use PaymosPayments\Service\PaymosPaymentHandler;
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
 * Paymos hosted-checkout payment plugin for Shopware 6 (6.5 and 6.6).
 *
 * Installs a single "Pay with crypto (Paymos)" payment method bound to
 * {@see PaymosPaymentHandler}. Compatible with the legacy async payment handler
 * model used across all of 6.5 and 6.6 (6.7 moved to AbstractPaymentHandler and
 * is out of scope for this artifact).
 */
class PaymosPayments extends Plugin
{
    /**
     * Stable technical name for the payment method (plugin-prefixed to avoid
     * collisions, per Shopware guidance).
     */
    private const PAYMENT_METHOD_TECHNICAL_NAME = 'paymos_crypto';

    public function install(InstallContext $installContext): void
    {
        $this->upsertPaymentMethod($installContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
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
            return;
        }

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(static::class, $context);

        $data = [
            // The handler is selected by this identifier (the handler FQCN).
            'handlerIdentifier' => PaymosPaymentHandler::class,
            'technicalName' => self::PAYMENT_METHOD_TECHNICAL_NAME,
            'name' => 'Pay with crypto (Paymos)',
            'description' => 'Pay with USDT or USDC. You will be redirected to the secure Paymos checkout.',
            'pluginId' => $pluginId,
            // Keep the method usable after the order is created (e.g. payment
            // failed and the buyer wants to retry).
            'afterOrderEnabled' => true,
            'translations' => [
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
            ],
        ];

        $this->paymentMethodRepository()->create([$data], $context);
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
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('handlerIdentifier', PaymosPaymentHandler::class))
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
