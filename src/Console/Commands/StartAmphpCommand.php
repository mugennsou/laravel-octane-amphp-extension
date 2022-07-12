<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Console\Commands;

use Amp\Future;
use Amp\Process\Process;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Octane\Commands\Command;
use Laravel\Octane\Commands\Concerns as OctaneConcerns;
use Mugennsou\LaravelOctaneExtension\Amphp\FileWatcher;
use Mugennsou\LaravelOctaneExtension\Amphp\ServerProcessInspector;
use Mugennsou\LaravelOctaneExtension\Amphp\ServerStateFile;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Process\PhpExecutableFinder;

use function Amp\async;
use function Amp\Future\await;

class StartAmphpCommand extends Command
{
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

    protected Process $process;

    public function __construct(protected readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

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

        $this->writeServerRunningMessage();

        $this->startServerWatcher();

        $cwd = base_path('vendor/mugennsou/laravel-octane-extension-amphp/bin');
        $command = [(new PhpExecutableFinder())->find() ?: 'php', $cwd . '/amphp-server.php'];
        $env = [
            'APP_ENV' => app()->environment(),
            'APP_BASE_PATH' => base_path(),
            'LARAVEL_OCTANE' => 1,
            'STATE_FILE' => $serverStateFile->path(),
        ];

        while (true) {
            [$code] = await([$this->runWorker($serverStateFile, $command, $cwd, $env)]);

            if ($code !== SIGUSR1) {
                $this->stopServer();

                break;
            }
        }

        return $code;
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
                'port' => (int)$this->option('port'),
                'maxRequests' => (int)$this->option('max-requests'),
                'publicPath' => public_path(),
                'storagePath' => storage_path(),
                'octaneConfig' => config('octane'),
            ]
        );
    }

    /**
     * Write the server start "message" to the console.
     *
     * @return void
     */
    protected function writeServerRunningMessage(): void
    {
        $this->info('Server running…');

        /** @var string $host */
        $host = $this->option('host');
        /** @var string $port */
        $port = $this->option('port');

        $this->output->writeln([
            '',
            sprintf('  Local: <fg=white;options=bold>http://%s:%s</>', $host, $port),
            '',
            '  <fg=yellow>Press Ctrl+C to stop the server</>',
            '',
        ]);
    }

    /**
     * Start the file watcher process for the server.
     */
    protected function startServerWatcher(): void
    {
        if (!$this->option('watch')) {
            return;
        }

        /** @var array<string> $watchFiles */
        $watchFiles = config('octane.watch');
        /** @var array<string> $paths */
        $paths = collect($watchFiles)->map(fn ($path): string => base_path($path))->toArray();

        $watcher = new FileWatcher($paths);

        EventLoop::repeat(
            2,
            function () use ($watcher): void {
                if (isset($this->process) && $this->process->isRunning() && $watcher->filesChanged()) {
                    $this->info('Application change detected. Restarting...');

                    $this->reloadServer();
                }
            }
        );
    }

    /**
     * Run a worker with command args.
     *
     * @param ServerStateFile $serverStateFile
     * @param array<int, string> $command
     * @param string $cwd
     * @param array<string, int|string> $env
     * @return Future<int>
     */
    protected function runWorker(ServerStateFile $serverStateFile, array $command, string $cwd, array $env): Future
    {
        /** @var Future<int> $future */
        $future = async(
            function (ServerStateFile $serverStateFile, array $command, string $cwd, array $env) {
                $this->process = $process = Process::start($command, $cwd, $env);

                $serverStateFile->writeProcessId($process->getPid());

                while (is_string($output = $process->getStdout()->read())) {
                    $this->writeServerOutput($output);
                }

                return $process->join();
            },
            $serverStateFile,
            $command,
            $cwd,
            $env
        );

        return $future;
    }

    /**
     * Write the server process output ot the console.
     *
     * @param string $output
     * @return void
     */
    protected function writeServerOutput(string $output): void
    {
        Str::of($output)
            ->explode(PHP_EOL)
            ->filter()
            ->each(
                function (string $output): void {
                    $record = json_decode($output, true);

                    if (!is_array($record)) {
                        $this->logger->info($output);

                        return;
                    }

                    if ($record['message'] === 'request') {
                        $this->handleStream($record['context']);

                        return;
                    }

                    if (method_exists($this->logger, $method = strtolower($record['level_name']))) {
                        $this->logger->{$method}($record['message'], $record['context'], $record['extra']);
                    }
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
        if (isset($this->process) && $this->process->isRunning()) {
            unset($this->process);

            $this->callSilent('octane-extension:stop-amphp');
        }
    }

    /**
     * Reload the server.
     *
     * @return void
     */
    protected function reloadServer(): void
    {
        if (isset($this->process) && $this->process->isRunning()) {
            unset($this->process);

            $this->callSilent('octane-extension:reload-amphp');
        }
    }
}
