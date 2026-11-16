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

    /** @var array<int, object> */
    public $lineItems = array();

    public function getOrderNumber()
    {
        return '10001';
    }

    public function getLineItems()
    {
        return $this->lineItems;
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
                if (array_key_exists('line_items', $fields)) {
                    $legacyTransaction->order->lineItems = $fields['line_items'];
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
                if (array_key_exists('line_items', $fields)) {
                    $fields['products'] = $fields['line_items'];
                    unset($fields['line_items']);
                }
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

function sw_test_line_item(array $spec)
{
    return new class($spec) {
        /** @var array<string, mixed> */
        private $spec;

        public function __construct(array $spec)
        {
            $this->spec = $spec;
        }

        public function getType()
        {
            return isset($this->spec['type']) ? $this->spec['type'] : 'product';
        }

        public function getParentId()
        {
            return isset($this->spec['parent_id']) ? $this->spec['parent_id'] : null;
        }

        public function getLabel()
        {
            return array_key_exists('label', $this->spec) ? $this->spec['label'] : null;
        }

        public function getDescription()
        {
            return array_key_exists('description', $this->spec) ? $this->spec['description'] : null;
        }

        public function getQuantity()
        {
            return array_key_exists('quantity', $this->spec) ? $this->spec['quantity'] : null;
        }

        public function getUnitPrice()
        {
            return array_key_exists('unit_price', $this->spec) ? $this->spec['unit_price'] : null;
        }

        public function getPrice()
        {
            if (!array_key_exists('unit_price', $this->spec)) {
                return null;
            }
            $unitPrice = $this->spec['unit_price'];

            return new class($unitPrice) {
                private $unitPrice;
                public function __construct($unitPrice) { $this->unitPrice = $unitPrice; }
                public function getUnitPrice() { return $this->unitPrice; }
            };
        }

        public function getCover()
        {
            if (!isset($this->spec['cover_url'])) {
                return null;
            }
            $url = $this->spec['cover_url'];

            return new class($url) {
                private $url;
                public function __construct($url) { $this->url = $url; }
                public function getUrl() { return $this->url; }
            };
        }

        public function getPayload()
        {
            return isset($this->spec['payload']) ? $this->spec['payload'] : array();
        }
    };
}

function sw_test_handler_line_items($era, array $specs)
{
    if ($era === '6.5/6.6') {
        $items = array();
        foreach ($specs as $spec) {
            $items[] = sw_test_line_item($spec);
        }

        return $items;
    }

    $rows = array();
    foreach ($specs as $spec) {
        $row = $spec;
        if (isset($spec['cover_url'])) {
            $row['image_url'] = $spec['cover_url'];
        }
        $rows[] = $row;
    }

    return $rows;
}

function test_sw_payment_handler_sends_single_product_across_both_eras()
{
    foreach (array('6.5/6.6', '6.7') as $era) {
        sw_write_generated_config();
        $invoices = new FakePaymosInvoices();
        $logger = new SwTestLogger();
        $handler = sw_handlers($invoices, $logger)[$era];

        $handler['change'](array(
            'amount' => '47.00',
            'line_items' => sw_test_handler_line_items($era, array(
                array(
                    'type' => 'product',
                    'label' => 'Canvas',
                    'description' => '<p>Giclée print</p>',
                    'cover_url' => 'https://shop.example/media/canvas.webp',
                    'quantity' => 2,
                    'unit_price' => 24.5,
                    'payload' => array(
                        'options' => array(array('group' => 'Color', 'option' => 'Blue')),
                    ),
                ),
            )),
        ));

        $redirect = $handler['pay']();
        assertSameValue('https://pay.paymos.test/inv_123', $redirect->getTargetUrl(), $era . ': redirect succeeds.');
        assertSameValue('47', $invoices->payloads[0]['amount'], $era . ': native total preserved.');
        assertSameValue(
            array(
                array(
                    'title' => 'Canvas · Blue',
                    'quantity' => 2,
                    'unit_amount' => '24.5',
                    'description' => 'Giclée print',
                    'image_url' => 'https://shop.example/media/canvas.webp',
                ),
            ),
            $invoices->payloads[0]['products'],
            $era . ': single product with variation option is sent.'
        );
    }
}

function test_sw_payment_handler_filters_non_product_lines_and_falls_back_all_or_nothing()
{
    foreach (array('6.5/6.6', '6.7') as $era) {
        sw_write_generated_config();
        $invoices = new FakePaymosInvoices();
        $logger = new SwTestLogger();
        $handler = sw_handlers($invoices, $logger)[$era];

        $handler['change'](array(
            'amount' => '42.50',
            'line_items' => sw_test_handler_line_items($era, array(
                array(
                    'type' => 'product',
                    'label' => 'Canvas',
                    'description' => 'Print',
                    'cover_url' => 'https://shop.example/canvas.png',
                    'quantity' => 2,
                    'unit_price' => 15.0,
                ),
                array(
                    'type' => 'promotion',
                    'label' => 'SAVE10',
                    'quantity' => 1,
                    'unit_price' => -10.0,
                ),
                array(
                    'type' => 'custom',
                    'label' => 'Gift wrap fee',
                    'quantity' => 1,
                    'unit_price' => 5.0,
                ),
                array(
                    'type' => 'credit',
                    'label' => 'Store credit',
                    'quantity' => 1,
                    'unit_price' => -2.5,
                ),
                array(
                    'type' => 'product',
                    'parent_id' => 'parent_1',
                    'label' => 'Nested option line',
                    'quantity' => 1,
                    'unit_price' => 0.0,
                ),
                array(
                    'type' => 'product',
                    'label' => 'Notebook',
                    'description' => '&nbsp;',
                    'cover_url' => 'http://shop.example/insecure.png',
                    'quantity' => 1,
                    'unit_price' => 12.5,
                ),
            )),
        ));

        $handler['pay']();
        $payload = $invoices->payloads[0];
        assertSameValue('42.5', $payload['amount'], $era . ': native total includes discounts/fees.');
        assertSameValue(
            array(
                array(
                    'title' => 'Canvas',
                    'quantity' => 2,
                    'unit_amount' => '15',
                    'description' => 'Print',
                    'image_url' => 'https://shop.example/canvas.png',
                ),
                array(
                    'title' => 'Notebook',
                    'quantity' => 1,
                    'unit_amount' => '12.5',
                ),
            ),
            $payload['products'],
            $era . ': non-product and nested child lines are excluded while valid products pass.'
        );

        // One invalid product item in a cart triggers all-or-nothing fallback to amount-only.
        sw_write_generated_config();
        $fbInvoices = new FakePaymosInvoices();
        $fbHandler = sw_handlers($fbInvoices, new SwTestLogger())[$era];
        $fbHandler['change'](array(
            'amount' => '30.00',
            'line_items' => sw_test_handler_line_items($era, array(
                array('type' => 'product', 'label' => 'Valid Item', 'quantity' => 1, 'unit_price' => 20.0),
                array('type' => 'product', 'label' => 'Fractional Item', 'quantity' => 1.5, 'unit_price' => 10.0),
            )),
        ));
        $fbHandler['pay']();
        assertFalseValue(array_key_exists('products', $fbInvoices->payloads[0]), $era . ': invalid line triggers amount-only fallback.');
        assertSameValue('30', $fbInvoices->payloads[0]['amount'], $era . ': grand total stays intact.');

        // > 50 product items triggers all-or-nothing fallback to amount-only.
        sw_write_generated_config();
        $overInvoices = new FakePaymosInvoices();
        $overHandler = sw_handlers($overInvoices, new SwTestLogger())[$era];
        $overSpecs = array();
        for ($i = 1; $i <= 51; $i++) {
            $overSpecs[] = array('type' => 'product', 'label' => 'Item ' . $i, 'quantity' => 1, 'unit_price' => 1.0);
        }
        $overHandler['change'](array(
            'amount' => '51.00',
            'line_items' => sw_test_handler_line_items($era, $overSpecs),
        ));
        $overHandler['pay']();
        assertFalseValue(array_key_exists('products', $overInvoices->payloads[0]), $era . ': >50 products triggers amount-only fallback.');
        assertSameValue('51', $overInvoices->payloads[0]['amount'], $era . ': grand total stays intact for >50 fallback.');
    }
}

function test_sw_payment_handler_reuses_invoice_on_presentation_change_across_both_eras()
{
    foreach (array('6.5/6.6', '6.7') as $era) {
        sw_write_generated_config();
        $invoices = new FakePaymosInvoices();
        $logger = new SwTestLogger();
        $handler = sw_handlers($invoices, $logger)[$era];

        $handler['change'](array(
            'amount' => '100.00',
            'line_items' => sw_test_handler_line_items($era, array(
                array('type' => 'product', 'label' => 'Item A', 'description' => 'Desc 1', 'quantity' => 1, 'unit_price' => 100.0),
            )),
        ));
        $first = $handler['pay']();

        $handler['change'](array(
            'line_items' => sw_test_handler_line_items($era, array(
                array('type' => 'product', 'label' => 'Item B', 'description' => 'Desc 2', 'quantity' => 2, 'unit_price' => 50.0),
            )),
        ));
        $second = $handler['pay']();

        assertSameValue($first->getTargetUrl(), $second->getTargetUrl(), $era . ': same invoice URL is reused.');
        assertSameValue(array('create', 'get inv_123'), $invoices->calls, $era . ': changed products alone must not cancel or recreate.');
    }
}
