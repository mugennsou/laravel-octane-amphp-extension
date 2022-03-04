<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension;

use Illuminate\Foundation\Application;
use Mugennsou\LaravelOctaneExtension\Amphp\ServerStateFile;
use Mugennsou\LaravelOctaneExtension\Console\Commands;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class OctaneExtensionAmphpServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-octane-amphp-extension')
            ->hasCommands(
                [
                    Commands\StartAmphpCommand::class,
                    Commands\StatusAmphpCommand::class,
                    Commands\ReloadAmphpCommand::class,
                    Commands\StopAmphpCommand::class,
                ]
            );
    }

    public function packageRegistered()
    {
        $this->app->bind(
            ServerStateFile::class,
            fn(Application $app): ServerStateFile => new ServerStateFile(
                $app['config']->get('octane.state_file', storage_path('logs/octane-server-state.json'))
            )
        );
    }
}
