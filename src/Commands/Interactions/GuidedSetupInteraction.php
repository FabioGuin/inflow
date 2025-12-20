<?php

namespace InFlow\Commands\Interactions;

use InFlow\Commands\InFlowCommand;
use InFlow\Constants\DisplayConstants;
use InFlow\Enums\Config\ConfigKey;

readonly class GuidedSetupInteraction
{
    public function __construct(
        private InFlowCommand $command
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function guidedSetup(): array
    {
        $this->command->line('<fg=cyan>Configuration Wizard</>');
        $this->command->line(DisplayConstants::SECTION_SEPARATOR);
        $this->command->newLine();

        $this->command->line('<fg=yellow>Sanitization</>');
        $this->command->line('  Clean the file (remove BOM, normalize newlines, etc.)?');

        $sanitize = $this->command->confirmWithBack(
            '  Enable sanitization? (y/n, or type "back" to cancel wizard)',
            true
        );

        if ($sanitize === null) {
            $this->command->line('  Configuration wizard cancelled.');

            return [];
        }

        $this->command->newLine();
        $this->command->success('Configuration complete! Starting processing...');
        $this->command->flushOutput();

        return [ConfigKey::Sanitize->value => $sanitize];
    }
}
