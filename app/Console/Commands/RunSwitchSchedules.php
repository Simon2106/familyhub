<?php

namespace App\Console\Commands;

use App\Services\HomeAssistant\HomeAssistant;
use App\Services\HomeAssistant\SwitchSchedules;
use Illuminate\Console\Command;

class RunSwitchSchedules extends Command
{
    protected $signature = 'familyhub:switch-schedules';

    protected $description = 'Turn scheduled switch groups on and off';

    public function handle(SwitchSchedules $schedules, HomeAssistant $home): int
    {
        if (! $home->isConfigured()) {
            return self::SUCCESS;
        }

        $fired = $schedules->run();

        foreach ($fired as $what) {
            $this->components->info($what);
        }

        return self::SUCCESS;
    }
}
