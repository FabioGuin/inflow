<?php

namespace InFlow\Services\Formatter;

use InFlow\ValueObjects\File\DetectedFormat;
use InFlow\ViewModels\FormatInfoViewModel;

/**
 * Formatter for format information
 */
readonly class FormatInfoFormatter
{
    public function format(DetectedFormat $format): FormatInfoViewModel
    {
        return new FormatInfoViewModel(
            title: 'Format Information',
            type: $format->type->value,
            delimiter: $format->delimiter,
            encoding: $format->encoding,
            hasHeader: $format->hasHeader,
        );
    }
}
