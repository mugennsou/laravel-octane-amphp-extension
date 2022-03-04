<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Console\Commands;

use Laravel\Octane\Commands\Command;
use Mugennsou\LaravelOctaneExtension\Amphp\ServerProcessInspector;

class StatusAmphpCommand extends Command
{
    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'octane-extension:status-amphp {--server= : The server that is running the application}';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Get the current status of the Octane extension server';

    /**
     * Indicates whether the command should be shown in the Artisan command list.
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * Handle the command.
     *
     * @param ServerProcessInspector $inspector
     * @return int
     */
    public function handle(ServerProcessInspector $inspector): int
    {
        if (!$inspector->serverIsRunning()) {
            $this->info('Octane server is not running.');

            return 1;
        }

        $this->info('Octane server is running.');

        return 0;
    }
}
