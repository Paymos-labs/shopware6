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
        class PaymentException extends \Exception
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
}

namespace Symfony\Component\Routing\Attribute {
    if (!class_exists(Route::class)) {
        class Route
        {
        }
    }
}
