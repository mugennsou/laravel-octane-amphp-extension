<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Amphp;

use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

class FileWatcher
{
    /** @var array<string, int> */
    protected array $mTime = [];

    /** @var array<string, string> */
    protected array $md5 = [];

    protected int $count = 0;

    /**
     * @param array<string> $paths
     */
    public function __construct(protected array $paths)
    {
        $this->init();
    }

    /**
     * Checks if tracked files have changed.
     *
     * @return bool
     */
    public function filesChanged(): bool
    {
        clearstatcache();

        $changed = 0;

        foreach ($this->mTime as $filePath => $mTime) {
            if (is_dir($filePath)) {
                unset($this->mTime[$filePath], $this->md5[$filePath]);

                $changed++;

                continue;
            }

            if (!file_exists($filePath)) {
                unset($this->mTime[$filePath], $this->md5[$filePath]);

                $changed++;

                continue;
            }

            $actualMTime = filemtime($filePath) ?: 0;

            if ($actualMTime !== $mTime) {
                $this->mTime[$filePath] = $actualMTime;

                $md5 = md5_file($filePath) ?: '';

                if ($md5 !== $this->md5[$filePath]) {
                    $this->md5[$filePath] = $md5;

                    $changed++;
                }
            }
        }

        if ($changed === 0) {
            $this->mTime = $this->md5 = [];
            [$latestCount, $this->count] = [$this->count, 0];

            $this->init();

            $changed = $this->count - $latestCount;
        }

        return $changed !== 0;
    }

    /**
     * Register files for change tracking
     */
    protected function init(): void
    {
        try {
            clearstatcache();

            foreach ($this->paths as $path) {
                $directories = explode(DIRECTORY_SEPARATOR, $path);
                $namePattern = null;

                if (count($directories) > 1) {
                    $last = array_pop($directories);

                    if (str_contains($last, '*.')) {
                        $path = implode(DIRECTORY_SEPARATOR, $directories);
                        $namePattern = $last;
                    }
                }

                if (is_dir($path) || str_contains($path, '*')) {
                    $finder = Finder::create()->in($path)->files();

                    if (isset($namePattern)) {
                        $finder->name($namePattern);
                    }

                    /**
                     * @var string $filePath
                     * @var SplFileInfo $file
                     */
                    foreach ($finder as $filePath => $file) {
                        $this->mTime[$filePath] = $file->getMTime();
                        $this->md5[$filePath] = md5_file($filePath) ?: '';

                        $this->count++;
                    }

                    continue;
                }

                if (is_file($path)) {
                    $file = new SplFileInfo($path);

                    $this->mTime[$path] = $file->getMTime();
                    $this->md5[$path] = md5_file($path) ?: '';

                    $this->count++;
                }
            }
        } catch (Throwable $e) {
            // silent
        }
    }
}
