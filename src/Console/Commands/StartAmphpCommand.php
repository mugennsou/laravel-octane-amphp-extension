<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Console\Commands;

use Illuminate\Support\Str;
use Laravel\Octane\Commands\Command;
use Laravel\Octane\Commands\Concerns as OctaneConcerns;
use Mugennsou\LaravelOctaneExtension\Amphp\ServerProcessInspector;
use Mugennsou\LaravelOctaneExtension\Amphp\ServerStateFile;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

use function Amp\Cluster\countCpuCores;

class StartAmphpCommand extends Command implements SignalableCommandInterface
{
    use OctaneConcerns\InteractsWithServers;
    use OctaneConcerns\InteractsWithEnvironmentVariables;

    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'octane-extension:start-amphp
                    {--host=127.0.0.1 : The IP address the server should bind to}
                    {--port=8000 : The port the server should be available on}
                    {--workers=auto : The number of workers that should be available to handle requests}
                    {--max-requests=500 : The number of requests to process before reloading the server}
                    {--watch : Automatically reload the server when the application is modified}';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Start the Octane extension server';

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
        if ($inspector->serverIsRunning()) {
            $this->error('Server is already running.');

            return 1;
        }

        $this->writeServerStateFile($serverStateFile);

        $this->forgetEnvironmentVariables();

        $cwd = base_path('vendor/mugennsou/laravel-octane-extension-amphp/bin');
        $command = array_merge(
            [(new PhpExecutableFinder())->find(), base_path('vendor/amphp/cluster/bin/cluster')],
            $this->clusterOptions(),
            [$cwd . '/amphp-server.php']
        );
        $env = [
            'APP_ENV' => app()->environment(),
            'APP_BASE_PATH' => base_path(),
            'LARAVEL_OCTANE' => 1,
            'STATE_FILE' => $serverStateFile->path(),
        ];

        /** @var Process $server */
        $server = tap(new Process($command, $cwd, $env))->start();

        $serverStateFile->writeProcessId($server->getPid());

        return $this->runServer($server, $inspector, 'amphp');
    }

    /**
     * Write the Amphp server state file.
     *
     * @param ServerStateFile $serverStateFile
     * @return void
     */
    protected function writeServerStateFile(ServerStateFile $serverStateFile): void
    {
        $serverStateFile->writeState(
            [
                'appName' => config('app.name', 'Laravel'),
                'host' => $this->option('host'),
                'port' => $this->option('port'),
                'workers' => $this->workerCount(),
                'maxRequests' => $this->option('max-requests'),
                'publicPath' => public_path(),
                'storagePath' => storage_path(),
                'octaneConfig' => config('octane'),
            ]
        );
    }

    /**
     * Get the default amphp cluster server options.
     *
     * @return array
     */
    protected function clusterOptions(): array
    {
        return [
            '--name',
            config('app.name', 'Laravel'),
            '--workers',
            $this->workerCount(),
            '--file',
            storage_path('logs/amphp.log'),
            '--log',
            app()->environment('local') ? LogLevel::INFO : LogLevel::ERROR,
        ];
    }

    /**
     * Get the number of workers that should be started.
     *
     * @return int
     */
    protected function workerCount(): int
    {
        return $this->option('workers') === 'auto' ? countCpuCores() : (int)$this->option('workers');
    }

    /**
     * Write the server process output ot the console.
     *
     * @param Process $server
     * @return void
     */
    protected function writeServerOutput(Process $server)
    {
        [$output, $errorOutput] = $this->getServerOutput($server);

        Str::of($output)
            ->explode("\n")
            ->filter()
            ->each(
                function ($output): void {
                    is_array($stream = json_decode($output, true))
                        ? $this->handleStream($stream)
                        : $this->info($output);
                }
            );

        Str::of($errorOutput)
            ->explode("\n")
            ->filter()
            ->groupBy(fn($output) => $output)
            ->each(
                function ($group): void {
                    is_array($stream = json_decode($output = $group->first(), true)) && isset($stream['type'])
                        ? $this->handleStream($stream)
                        : $this->raw($output);
                }
            );
    }

    /**
     * Stop the server.
     *
     * @return void
     */
    protected function stopServer(): void
    {
        $this->callSilent('octane-extension:stop-amphp');
    }
}
