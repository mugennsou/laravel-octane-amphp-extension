<?php

namespace Mugennsou\LaravelOctaneExtension\Tests;

use Laravel\Octane\RoadRunner\ServerStateFile;

class AmphpServerStateFileTest extends TestCase
{
    public function test_server_state_file_can_be_managed()
    {
        $stateFile = new ServerStateFile(sys_get_temp_dir() . '/amphp.json');

        $stateFile->delete();

        // Read file...
        $this->assertEquals(['masterProcessId' => null, 'state' => []], $stateFile->read());

        // Write file...
        $stateFile->writeProcessId(1);
        $stateFile->writeState(['name' => 'mugennsou']);
        $this->assertEquals(['masterProcessId' => 1, 'state' => ['name' => 'mugennsou']], $stateFile->read());

        // Delete file...
        $stateFile->delete();
        $this->assertEquals(['masterProcessId' => null, 'state' => []], $stateFile->read());
    }
}
