<?php

declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Write\Command {
    abstract class WriteCommand
    {
        private $entity;
        private $payload;
        private $primaryKey;

        public function __construct($entity, array $payload, array $primaryKey)
        {
            $this->entity = $entity;
            $this->payload = $payload;
            $this->primaryKey = $primaryKey;
        }

        public function getEntityName() { return $this->entity; }

        public function getPayload() { return $this->payload; }
        public function getPrimaryKey() { return $this->primaryKey; }
    }

    class InsertCommand extends WriteCommand {}
    class UpdateCommand extends WriteCommand {}
}

namespace Shopware\Core\Framework\DataAbstractionLayer\Write {
    class WriteContext
    {
        private $context;
        private function __construct($context) { $this->context = $context; }
        public static function createFromContext($context) { return new self($context); }
        public function getContext() { return $this->context; }
    }
}

namespace Shopware\Core\Framework\DataAbstractionLayer\Write\Validation {
    class PreWriteValidationEvent
    {
        private $context;
        private $commands;

        public function __construct(\Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext $context, array $commands)
        {
            $this->context = $context;
            $this->commands = $commands;
        }

        public function getContext() { return $this->context->getContext(); }
        public function getCommands() { return $this->commands; }
    }
}
