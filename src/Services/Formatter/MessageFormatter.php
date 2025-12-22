<?php

namespace InFlow\Services\Formatter;

use InFlow\Enums\UI\MessageType;
use InFlow\ViewModels\MessageViewModel;

/**
 * Formatter for simple messages
 */
readonly class MessageFormatter
{
    public function format(string $message, MessageType $type): MessageViewModel
    {
        return new MessageViewModel(
            message: $message,
            type: $type,
        );
    }
}
