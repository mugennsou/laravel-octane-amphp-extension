<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Console\Commands;

use Laravel\Octane\Commands\Command;
use Mugennsou\LaravelOctaneExtension\Amphp\ServerProcessInspector;
use Mugennsou\LaravelOctaneExtension\Amphp\ServerStateFile;

class StopAmphpCommand extends Command
{
    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'octane-extension:stop-amphp {--server= : The server that is running the application}';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Stop the Octane extension server';

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
     * @param ServerStateFile $serverStateFile
     * @return int
     */
    public function handle(ServerProcessInspector $inspector, ServerStateFile $serverStateFile): int
    {
        if (!$inspector->serverIsRunning()) {
            $serverStateFile->delete();

            $this->error('Amphp server is not running.');

            return 1;
        }

        $this->info('Stopping server...');

        if (!$inspector->stopServer()) {
            $this->error('Failed to stop Amphp server.');

            return 1;
        }

        $serverStateFile->delete();

        return 0;
    }
}
