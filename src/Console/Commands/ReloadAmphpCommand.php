<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Console\Commands;

use Laravel\Octane\Commands\Command;
use Mugennsou\LaravelOctaneExtension\Amphp\ServerProcessInspector;

class ReloadAmphpCommand extends Command
{
    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'octane-extension:reload-amphp';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Reload the Octane extension workers';

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
            $this->error('Octane extension server is not running.');

            return 1;
        }

        $this->info('Reloading workers...');

        $inspector->reloadServer();

        return 0;
    }
}
