<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Tests;

use Amp\PHPUnit\AsyncTestCase;
use Illuminate\Foundation\Application;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Octane;
use Mockery;

class TestCase extends AsyncTestCase
{
    protected function createApplication(): Application
    {
        $factory = new ApplicationFactory(realpath(__DIR__ . '/../vendor/orchestra/testbench-core/laravel'));

        $app = $this->appFactory()->createApplication();

        $factory->warm($app, Octane::defaultServicesToWarm());

        return $app;
    }

    protected function appFactory(): ApplicationFactory
    {
        return new ApplicationFactory(realpath(__DIR__ . '/../vendor/orchestra/testbench-core/laravel'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Mockery::close();
    }
}
