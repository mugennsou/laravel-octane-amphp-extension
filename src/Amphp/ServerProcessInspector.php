<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Amphp;

use Laravel\Octane\PosixExtension;

class ServerProcessInspector
{
    public function __construct(protected ServerStateFile $serverStateFile, protected PosixExtension $posix)
    {
    }

    /**
     * Determine if the Amphp server process is running.
     *
     * @return bool
     */
    public function serverIsRunning(): bool
    {
        ['masterProcessId' => $masterProcessId] = $this->serverStateFile->read();

        return $masterProcessId && $this->posix->kill($masterProcessId, 0);
    }

    /**
     * Reload the Amphp workers.
     *
     * @return void
     */
    public function reloadServer(): void
    {
        ['masterProcessId' => $masterProcessId] = $this->serverStateFile->read();

        $this->posix->kill($masterProcessId, defined('SIGUSR1') ? SIGUSR1 : 10);
    }

    /**
     * Stop the Amphp server.
     *
     * @return bool
     */
    public function stopServer(): bool
    {
        ['masterProcessId' => $masterProcessId] = $this->serverStateFile->read();

        return $this->posix->kill($masterProcessId, defined('SIGTERM') ? SIGTERM : 15);
    }
}
