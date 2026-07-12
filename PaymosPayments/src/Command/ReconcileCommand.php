<?php

declare(strict_types=1);

namespace PaymosPayments\Command;

use PaymosPayments\Service\Config;
use PaymosPayments\Service\PaymosInvoiceStore;
use PaymosPayments\Service\Reconciler;
use PaymosPayments\Service\ShopwareGateway;
use PaymosPayments\Service\CredentialStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recovery for missed webhooks: re-pulls open Paymos invoices from the API and
 * re-applies them through the same verify/guard/map path used by the webhook.
 *
 *   bin/console paymos:reconcile
 *
 * Cron this every few minutes for defence in depth. Shopware's server already
 * retries webhook delivery at-least-once, so this is a safety net, not the
 * primary path. Implemented as a Symfony console command (stable across 6.5 and
 * 6.6) rather than a ScheduledTask, whose handler contract diverges between
 * those two versions.
 */
#[AsCommand(
    name: 'paymos:reconcile',
    description: 'Re-pull open Paymos invoices and reconcile Shopware order transactions.'
)]
final class ReconcileCommand extends Command
{
    /** @var ShopwareGateway */
    private $gateway;

    /** @var PaymosInvoiceStore */
    private $invoiceStore;
    private $credentialStore;

    public function __construct(ShopwareGateway $gateway, PaymosInvoiceStore $invoiceStore, CredentialStore $credentialStore)
    {
        parent::__construct();
        $this->gateway = $gateway;
        $this->invoiceStore = $invoiceStore;
        $this->credentialStore = $credentialStore;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->credentialStore->settings(array());
        if (!Config::fromSettings($settings)->isConfigured()) {
            $output->writeln('<comment>Paymos is not configured yet; nothing to reconcile.</comment>');

            return Command::SUCCESS;
        }

        $reconciled = (new Reconciler($this->gateway, $this->invoiceStore))->run($settings);
        $output->writeln(sprintf('<info>Paymos reconcile complete. Orders updated to paid: %d.</info>', $reconciled));

        return Command::SUCCESS;
    }
}
