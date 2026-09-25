<?php

declare(strict_types=1);

namespace Symfony\Bundle\FrameworkBundle\Controller {
    if (!class_exists(AbstractController::class)) {
        class AbstractController
        {
        }
    }
}

namespace Symfony\Component\Console\Command {
    if (!class_exists(Command::class)) {
        class Command
        {
            protected static $defaultName = '';
            protected static $defaultDescription = '';

            protected function configure()
            {
            }

            protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
            {
                return 0;
            }
        }
    }
}

namespace Symfony\Component\Console\Input {
    if (!interface_exists(InputInterface::class)) {
        interface InputInterface
        {
        }
    }
}

namespace Symfony\Component\Console\Output {
    if (!interface_exists(OutputInterface::class)) {
        interface OutputInterface
        {
        }
    }
}

namespace Shopware\Core\Framework {
    if (!class_exists(Plugin::class)) {
        class Plugin
        {
        }
    }
}

namespace Shopware\Core\Framework\Migration {
    if (!class_exists(MigrationStep::class)) {
        class MigrationStep
        {
        }
    }
}

namespace Shopware\Core\Framework {
    if (!class_exists(Bundle::class) && !class_exists('Shopware\Core\Framework\Bundle')) {
        class Bundle
        {
        }
    }
}

namespace Shopware\Core\System\SalesChannel {
    if (!class_exists(SalesChannelEntity::class)) {
        class SalesChannelEntity
        {
        }
    }
}

namespace Shopware\Core\Checkout\Payment\Cart\PaymentHandler {
    if (!class_exists(AbstractPaymentHandler::class)) {
        abstract class AbstractPaymentHandler
        {
        }
    }
    if (!interface_exists(AsynchronousPaymentHandlerInterface::class)) {
        interface AsynchronousPaymentHandlerInterface
        {
        }
    }
    if (!class_exists(PaymentTransactionStruct::class)) {
        class PaymentTransactionStruct
        {
        }
    }
}

namespace Shopware\Core\Checkout\Payment {
    if (!class_exists(PaymentException::class)) {
        /**
         * The slice of the real class the handlers rely on: the HttpException
         * constructor (status, error code, message, parameters, previous) and
         * the 6.6+ asyncProcessInterrupted(). The storefront reads getErrorCode()
         * into ?error-code= on the edit-order page (PaymentService /
         * PaymentProcessor), so the code is what the buyer ends up seeing.
         */
        class PaymentException extends \Exception
        {
            const PAYMENT_ASYNC_PROCESS_INTERRUPTED = 'CHECKOUT__ASYNC_PAYMENT_PROCESS_INTERRUPTED';
            const PAYMENT_ASYNC_FINALIZE_INTERRUPTED = 'CHECKOUT__ASYNC_PAYMENT_FINALIZE_INTERRUPTED';
            const PAYMENT_CUSTOMER_CANCELED_EXTERNAL = 'CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT';

            /** @var int */
            protected $statusCode;

            /** @var string */
            protected $errorCode;

            /** @var array<string, mixed> */
            protected $parameters;

            public function __construct(int $statusCode, string $errorCode, string $message, array $parameters = array(), ?\Throwable $previous = null)
            {
                $this->statusCode = $statusCode;
                $this->errorCode = $errorCode;
                $this->parameters = $parameters;
                parent::__construct(strtr($message, self::placeholders($parameters)), 0, $previous);
            }

            public static function asyncProcessInterrupted(string $orderTransactionId, string $errorMessage, ?\Throwable $e = null): self
            {
                return new self(400, self::PAYMENT_ASYNC_PROCESS_INTERRUPTED, 'The asynchronous payment process was interrupted due to the following error:' . \PHP_EOL . '{{ errorMessage }}', array('errorMessage' => $errorMessage, 'orderTransactionId' => $orderTransactionId), $e);
            }

            public static function asyncFinalizeInterrupted(string $orderTransactionId, string $errorMessage, ?\Throwable $e = null): self
            {
                return new self(400, self::PAYMENT_ASYNC_FINALIZE_INTERRUPTED, 'The asynchronous payment finalize was interrupted due to the following error:' . \PHP_EOL . '{{ errorMessage }}', array('errorMessage' => $errorMessage, 'orderTransactionId' => $orderTransactionId), $e);
            }

            public static function customerCanceled(string $orderTransactionId, string $additionalInformation, ?\Throwable $e = null): self
            {
                return new self(400, self::PAYMENT_CUSTOMER_CANCELED_EXTERNAL, 'The customer canceled the external payment process. {{ additionalInformation }}', array('additionalInformation' => $additionalInformation, 'orderTransactionId' => $orderTransactionId), $e);
            }

            public function getErrorCode(): string
            {
                return $this->errorCode;
            }

            public function getStatusCode(): int
            {
                return $this->statusCode;
            }

            /** @return mixed */
            public function getParameter(string $key)
            {
                return isset($this->parameters[$key]) ? $this->parameters[$key] : null;
            }

            /** @return array<string, string> */
            private static function placeholders(array $parameters)
            {
                $map = array();
                foreach ($parameters as $key => $value) {
                    if (is_scalar($value)) {
                        $map['{{ ' . $key . ' }}'] = (string) $value;
                    }
                }

                return $map;
            }
        }
    }
}

namespace Shopware\Core\Checkout\Payment\Cart {
    // The real homes of the two transaction structs the handlers type-hint
    // (6.5/6.6 and 6.7). Tests hand in subclasses carrying the getters.
    if (!class_exists(AsyncPaymentTransactionStruct::class)) {
        class AsyncPaymentTransactionStruct
        {
        }
    }
    if (!class_exists(PaymentTransactionStruct::class)) {
        class PaymentTransactionStruct
        {
        }
    }
}

namespace Psr\Log {
    if (!interface_exists(LoggerInterface::class)) {
        interface LoggerInterface
        {
        }
    }
}

namespace Shopware\Core\Checkout\Order {
    if (!class_exists(OrderEntity::class)) {
        class OrderEntity
        {
        }
    }
}

namespace Shopware\Core\Framework {
    if (!class_exists(Context::class)) {
        class Context
        {
        }
    }
}

namespace Shopware\Core\Framework\DataAbstractionLayer {
    if (!class_exists(EntityRepository::class)) {
        class EntityRepository
        {
        }
    }
    if (!class_exists(Search\Criteria::class)) {
    }
}

namespace Shopware\Core\Framework\DataAbstractionLayer\Search {
    if (!class_exists(Criteria::class)) {
        class Criteria
        {
        }
    }
}

namespace Shopware\Core\Framework\DataAbstractionLayer\Search\Filter {
    if (!class_exists(EqualsFilter::class)) {
        class EqualsFilter
        {
        }
    }
}

namespace Shopware\Core\Framework\Struct {
    if (!class_exists(Struct::class)) {
        class Struct
        {
        }
    }
}

namespace Shopware\Core\Framework\Validation\DataBag {
    if (!class_exists(RequestDataBag::class)) {
        class RequestDataBag
        {
        }
    }
}

namespace Shopware\Core\System\SystemConfig {
    if (!class_exists(SystemConfigService::class)) {
        class SystemConfigService
        {
        }
    }
}

namespace Shopware\Core\System\SalesChannel {
    if (!class_exists(SalesChannelContext::class)) {
        class SalesChannelContext
        {
        }
    }
}

namespace Shopware\Storefront\Controller {
    if (!class_exists(StorefrontController::class)) {
        class StorefrontController
        {
        }
    }
}

namespace Shopware\Core\Framework\Plugin\Context {
    if (!class_exists(InstallContext::class)) {
        class InstallContext
        {
        }
    }
    if (!class_exists(UninstallContext::class)) {
        class UninstallContext
        {
        }
    }
    if (!class_exists(ActivateContext::class)) {
        class ActivateContext
        {
        }
    }
    if (!class_exists(DeactivateContext::class)) {
        class DeactivateContext
        {
        }
    }
}

namespace Shopware\Core\Framework\Plugin\Util {
    if (!class_exists(PluginIdProvider::class)) {
        class PluginIdProvider
        {
        }
    }
}

namespace Symfony\Component\HttpFoundation {
    if (!class_exists(JsonResponse::class)) {
        class JsonResponse
        {
        }
    }
    if (!class_exists(Request::class)) {
        class Request
        {
        }
    }
    if (!class_exists(RedirectResponse::class)) {
        class RedirectResponse
        {
            /** @var string */
            private $targetUrl;

            public function __construct(string $url)
            {
                $this->targetUrl = $url;
            }

            public function getTargetUrl(): string
            {
                return $this->targetUrl;
            }
        }
    }
}

namespace Symfony\Component\Routing\Attribute {
    if (!class_exists(Route::class)) {
        class Route
        {
        }
    }
}
