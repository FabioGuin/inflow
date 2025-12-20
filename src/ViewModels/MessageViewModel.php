<?php

namespace InFlow\ViewModels;

use InFlow\Enums\UI\MessageType;

/**
 * View Model for simple messages (success, error, warning, info)
 */
readonly class MessageViewModel
{
    public function __construct(
        public string $message,
        public MessageType $type,
    ) {}
}
