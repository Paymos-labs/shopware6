<?php

declare(strict_types=1);

use PaymosPayments\Service\CheckoutProcessor;
use PaymosPayments\Service\CredentialStore;
use PaymosPayments\Service\InMemoryInvoiceStore;
use PaymosPayments\Service\PaymosPaymentHandler;
use PaymosPayments\Service\PaymosPaymentHandler67;
use Shopware\Core\Checkout\Payment\PaymentException;

// BUG-189: a blocked invoice replacement (BUG-166) was thrown as Shopware's
// stock asyncProcessInterrupted. Shopware fails the transaction and sends the
// buyer to the edit-order page with ?error-code=CHECKOUT__ASYNC_PAYMENT_PROCESS_INTERRUPTED
// (6.5 PaymentService::handlePaymentByOrder, 6.6/6.7 PaymentProcessor::pay),
// and that page answers ANY error code with account.externalPaymentFailure —
// "please change the payment method or try again" — under a "Complete payment"
// button. The old invoice may already be paid. The handler must hand the
// storefront a code of its own, which the plugin's template answers with the
// SDK buyer message and no way to pay again.

const SW_REVIEW_ERROR_CODE = 'PAYMOS__ORDER_NEEDS_REVIEW';

final class SwTestSystemConfig extends \Shopware\Core\System\SystemConfig\SystemConfigService
{
    /** @return mixed */
    public function get($key, $salesChannelId = null)
    {
        return null;
    }

    public function getString($key, $salesChannelId = null)
    {
        return substr((string) $key, -5) === '.mode' ? 'sandbox' : '';
    }

    public function getBool($key, $salesChannelId = null)
    {
        return false;
    }
}

final class SwTestLogger implements \Psr\Log\LoggerInterface
{
    /** @var array<int, array{message: string, context: array}> */
    public $errors = array();

    public function error($message, array $context = array())
    {
        $this->errors[] = array('message' => (string) $message, 'context' => $context);
    }
}

final class SwTestOrder extends \Shopware\Core\Checkout\Order\OrderEntity
{
    /** @var string */
    public $currencyIso = 'USD';

    public function getOrderNumber()
    {
        return '10001';
    }

    public function getCurrency()
    {
        $iso = $this->currencyIso;

        return new class($iso) {
            /** @var string */
            private $iso;

            public function __construct($iso)
            {
                $this->iso = $iso;
            }

            public function getIsoCode()
            {
                return $this->iso;
            }
        };
    }

    public function getOrderCustomer()
    {
        return new class() {
            public function getCustomerId()
            {
                return 'cust_77';
            }
        };
    }
}

final class SwTestAsyncTransaction extends \Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct
{
    /** @var string */
    public $amount = '100.00';

    /** @var SwTestOrder */
    public $order;

    public function __construct()
    {
        $this->order = new SwTestOrder();
    }

    public function getOrderTransaction()
    {
        $amount = $this->amount;

        return new class($amount) {
            /** @var string */
            private $amount;

            public function __construct($amount)
            {
                $this->amount = $amount;
            }

            public function getId()
            {
                return 'txn_1';
            }

            public function getAmount()
            {
                $amount = $this->amount;

                return new class($amount) {
                    /** @var string */
                    private $amount;

                    public function __construct($amount)
                    {
                        $this->amount = $amount;
                    }

                    public function getTotalPrice()
                    {
                        return (float) $this->amount;
                    }
                };
            }
        };
    }

    public function getOrder()
    {
        return $this->order;
    }

    public function getReturnUrl()
    {
        return 'https://shop.test/payment/finalize-transaction?_sw_payment_token=tok123';
    }
}

final class SwTestTransaction67 extends \Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct
{
    public function getOrderTransactionId()
    {
        return 'txn_1';
    }

    public function getReturnUrl()
    {
        return 'https://shop.test/payment/finalize-transaction?_sw_payment_token=tok123';
    }
}

final class SwTestSalesChannelContext extends \Shopware\Core\System\SalesChannel\SalesChannelContext
{
    public function getSalesChannelId()
    {
        return 'sc_1';
    }
}

/**
 * The two handler eras behind one call, so every scenario runs on both.
 *
 * @return array<string, array{pay: callable, change: callable}>
 */
function sw_handlers(FakePaymosInvoices $invoices, SwTestLogger $logger)
{
    $store = new InMemoryInvoiceStore();
    $processor = new CheckoutProcessor($store, static function () use ($invoices) {
        return new FakePaymosClient($invoices);
    });
    $systemConfig = new SwTestSystemConfig();
    $credentials = new CredentialStore($systemConfig, 'kernel-secret');

    $legacyGateway = new FakeShopwareGateway();
    $legacy = new PaymosPaymentHandler($processor, $legacyGateway, $systemConfig, $logger, $credentials);
    $legacyTransaction = new SwTestAsyncTransaction();

    $gateway67 = new FakeShopwareGateway();
    $gateway67->orderContexts['txn_1'] = array(
        'order_number' => '10001',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 'cust_77',
        'sales_channel_id' => 'sc_1',
    );
    $handler67 = new PaymosPaymentHandler67($processor, $gateway67, $systemConfig, $logger, $credentials);

    return array(
        '6.5/6.6' => array(
            'pay' => static function () use ($legacy, $legacyTransaction) {
                return $legacy->pay(
                    $legacyTransaction,
                    new \Shopware\Core\Framework\Validation\DataBag\RequestDataBag(),
                    new SwTestSalesChannelContext()
                );
            },
            'change' => static function (array $fields) use ($legacyTransaction) {
                if (isset($fields['amount'])) {
                    $legacyTransaction->amount = $fields['amount'];
                }
                if (isset($fields['currency'])) {
                    $legacyTransaction->order->currencyIso = $fields['currency'];
                }
            },
        ),
        '6.7' => array(
            'pay' => static function () use ($handler67) {
                return $handler67->pay(
                    new \Symfony\Component\HttpFoundation\Request(),
                    new SwTestTransaction67(),
                    new \Shopware\Core\Framework\Context(),
                    null
                );
            },
            'change' => static function (array $fields) use ($gateway67) {
                $gateway67->orderContexts['txn_1'] = array_merge($gateway67->orderContexts['txn_1'], $fields);
            },
        ),
    );
}

/**
 * @return PaymentException
 */
function sw_pay_expecting_failure(callable $pay, $label)
{
    try {
        $pay();
    } catch (PaymentException $e) {
        return $e;
    }

    throw new RuntimeException($label . ': pay() must interrupt the payment.');
}

function test_sw_payment_handler_blocked_replacement_hands_the_storefront_the_review_code()
{
    foreach (array('6.5/6.6', '6.7') as $era) {
        sw_write_generated_config();
        $invoices = new FakePaymosInvoices(array(), array('invoice_id' => 'inv_123', 'status' => 'awaiting_payment'));
        $logger = new SwTestLogger();
        $handler = sw_handlers($invoices, $logger)[$era];

        $first = $handler['pay']();
        assertSameValue('https://pay.paymos.test/inv_123', $first->getTargetUrl(), $era . ': the first checkout goes to Paymos.');

        // The order total changed while the buyer already picked a network on
        // the old invoice: the server refuses the cancel, the read says payable.
        $handler['change'](array('amount' => '150.00'));
        $invoices->cancelException = sw_api_error(409, 'invoice_cannot_be_cancelled', 'Invoice cannot be cancelled in status awaiting_payment.');

        $e = sw_pay_expecting_failure($handler['pay'], $era);

        assertSameValue(SW_REVIEW_ERROR_CODE, $e->getErrorCode(), $era . ': the storefront gets the Paymos review code, not the stock "change the payment method" one.');
        assertSameValue('txn_1', $e->getParameter('orderTransactionId'), $era . ': Shopware still learns which transaction to fail.');
        assertSameValue(
            'The store needs to review this order before payment can continue. Please contact the store.',
            $e->getMessage(),
            $era . ': the message is the SDK buyer message, with no "could not create the payment" prefix.'
        );
        assertTrueValue($e->getPrevious() instanceof \Paymos\Plugin\InvoiceReplacementBlockedException, $era . ': the SDK exception stays attached.');
        assertSameValue(array('create', 'cancel inv_123', 'get inv_123'), $invoices->calls, $era . ': no second invoice is cut.');

        $logged = end($logger->errors);
        assertTrueValue(isset($logged['context']['manual_review']) && strpos($logged['context']['manual_review'], 'inv_123') !== false, $era . ': the merchant log carries the manual-review summary.');
    }
}

function test_sw_payment_handler_any_other_failure_keeps_the_stock_interruption()
{
    foreach (array('6.5/6.6', '6.7') as $era) {
        sw_write_generated_config();
        $invoices = new FakePaymosInvoices();
        $logger = new SwTestLogger();
        $handler = sw_handlers($invoices, $logger)[$era];
        $handler['change'](array('currency' => ''));

        $e = sw_pay_expecting_failure($handler['pay'], $era);

        assertSameValue(PaymentException::PAYMENT_ASYNC_PROCESS_INTERRUPTED, $e->getErrorCode(), $era . ': a creation failure keeps Shopware\'s own code — trying again or paying another way is right there.');
        assertContainsValue('Shopware order currency is missing.', $e->getMessage(), $era . ': the cause stays in the exception for the Shopware log.');
        assertSameValue(array(), $invoices->calls, $era . ': nothing reached Paymos.');
    }
}
