<?php

namespace InFlow\Enums\UI;

/**
 * Enum representing message types for presentation
 */
enum MessageType: string
{
    case Success = 'success';
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
