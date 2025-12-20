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

    public function success(string $message): MessageViewModel
    {
        return $this->format($message, MessageType::Success);
    }

    public function error(string $message): MessageViewModel
    {
        return $this->format($message, MessageType::Error);
    }

    public function warning(string $message): MessageViewModel
    {
        return $this->format($message, MessageType::Warning);
    }

    public function info(string $message): MessageViewModel
    {
        return $this->format($message, MessageType::Info);
    }
}
