<?php

declare(strict_types=1);

namespace Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund {
    if (!class_exists(OrderTransactionCaptureRefundStateHandler::class)) {
        class OrderTransactionCaptureRefundStateHandler {}
    }
}

namespace Shopware\Core\Checkout\Order\Aggregate\OrderTransaction {
    if (!class_exists(OrderTransactionStateHandler::class)) {
        class OrderTransactionStateHandler {}
    }
}

namespace {
    class SwNativeRefundStates extends \Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler
    {
        public $db;
        public $calls = [];
        public function __construct($db) { $this->db = $db; }
        private function record($action, $state, $id, $context)
        {
            assertTrueValue($context->hasState(\Shopware\Core\Framework\Context::SKIP_TRIGGER_FLOW), 'Native transition suppresses core FlowBuilder');
            $this->calls[] = [$action, $id, $context];
            $this->db->native['refund_state'] = $state;
        }
        public function process($id, $context) { $this->record('process', 'in_progress', $id, $context); }
        public function complete($id, $context) { $this->record('complete', 'completed', $id, $context); }
        public function fail($id, $context) { $this->record('fail', 'failed', $id, $context); }
        public function cancel($id, $context) { $this->record('cancel', 'cancelled', $id, $context); }
        public function reopen($id, $context) { $this->record('reopen', 'open', $id, $context); }
    }

    class SwNativePaymentStates extends \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler
    {
        public $db;
        public $calls = [];
        public function __construct($db) { $this->db = $db; }
        private function record($state, $context)
        {
            assertTrueValue($context->hasState(\Shopware\Core\Framework\Context::SKIP_TRIGGER_FLOW), 'Aggregate transition suppresses native mail');
            $this->calls[] = $state;
            $this->db->native['state'] = $state;
        }
        public function refund($id, $context) { $this->record('refunded', $context); }
        public function reopen($id, $context) { $this->record('open', $context); }
        public function paid($id, $context) { $this->record('paid', $context); }
    }

    class SwNativeDb extends SwRefundDb
    {
        public $paymentRow;
        public $refundRow;
        public $others = 0;
        public $positions = 0;
        public $captures = [];
        public function fetchAssociative($sql, $bind = [])
        {
            if (strpos($sql, 'FROM order_transaction t') !== false) {
                return array_replace($this->paymentRow, ['state' => $this->native['state']]);
            }
            if (strpos($sql, 'FROM order_transaction_capture_refund r') !== false) {
                return array_replace($this->refundRow, ['refund_state' => $this->native['refund_state']]);
            }
            return parent::fetchAssociative($sql, $bind);
        }
        public function fetchAllAssociative($sql, $bind = [])
        {
            if (strpos($sql, 'SELECT LOWER(HEX(id)) AS id FROM order_transaction_capture') === 0) { return $this->captures; }
            return parent::fetchAllAssociative($sql, $bind);
        }
        public function fetchOne($sql, $bind = [])
        {
            if (strpos($sql, 'SELECT COUNT(*) FROM paymos_payment_invoice') === 0) { return 1; }
            if (strpos($sql, 'SELECT COUNT(*) FROM order_transaction_capture_refund_position') === 0) { return $this->positions; }
            if (strpos($sql, 'SELECT COUNT(*) FROM order_transaction_capture_refund r') === 0) { return $this->others; }
            if (strpos($sql, 'SELECT LOWER(HEX(s.id))') === 0) { return str_repeat('f', 32); }
            return parent::fetchOne($sql, $bind);
        }
    }

    class SwNativeRepository extends \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository
    {
        public $writes = [];
        public function create($data, $context) { $this->writes[] = $data; }
    }
}
