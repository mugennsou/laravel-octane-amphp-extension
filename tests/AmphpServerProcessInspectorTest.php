<?php

namespace Mugennsou\LaravelOctaneExtension\Tests;

use Laravel\Octane\PosixExtension;
use Mockery;
use Mugennsou\LaravelOctaneExtension\Amphp\ServerProcessInspector;
use Mugennsou\LaravelOctaneExtension\Amphp\ServerStateFile;

class AmphpServerProcessInspectorTest extends TestCase
{
    public function test_can_determine_if_amphp_server_process_is_running_when_master_is_running()
    {
        $inspector = new ServerProcessInspector(
            $processIdFile = new ServerStateFile(sys_get_temp_dir() . '/amphp.pid'),
            $posix = Mockery::mock(PosixExtension::class)
        );

        $processIdFile->writeProcessId($masterProcessId = 1);

        $posix->shouldReceive('kill')->with($masterProcessId, 0)->andReturn(true);

        $this->assertTrue($inspector->serverIsRunning());

        $processIdFile->delete();
    }

    public function test_can_determine_if_amphp_server_process_is_running_when_master_cant_be_communicated_with()
    {
        $inspector = new ServerProcessInspector(
            $processIdFile = new ServerStateFile(sys_get_temp_dir() . '/amphp.pid'),
            $posix = Mockery::mock(PosixExtension::class)
        );

        $processIdFile->writeProcessId($masterProcessId = 1);

        $posix->shouldReceive('kill')->with($masterProcessId, 0)->andReturn(false);

        $this->assertFalse($inspector->serverIsRunning());

        $processIdFile->delete();
    }

    /** @doesNotPerformAssertions @test */
    public function test_amphp_server_process_can_be_reload()
    {
        $inspector = new ServerProcessInspector(
            $processIdFile = new ServerStateFile(sys_get_temp_dir() . '/amphp.pid'),
            $posix = Mockery::mock(PosixExtension::class)
        );

        $processIdFile->writeProcessId($masterProcessId = 1);

        $posix->shouldReceive('kill')
            ->with($masterProcessId, defined('SIGUSR1') ? SIGUSR1 : 10)
            ->andReturn(true)
            ->once();

        $inspector->reloadServer();
    }

    public function test_amphp_server_process_can_be_stop()
    {
        $inspector = new ServerProcessInspector(
            $processIdFile = new ServerStateFile(sys_get_temp_dir() . '/amphp.pid'),
            $posix = Mockery::mock(PosixExtension::class)
        );

        $processIdFile->writeProcessId($masterProcessId = 1);

        $posix->shouldReceive('kill')
            ->with($masterProcessId, defined('SIGTERM') ? SIGTERM : 15)
            ->andReturn(true)
            ->once();

        $this->assertTrue($inspector->stopServer());
    }
}
