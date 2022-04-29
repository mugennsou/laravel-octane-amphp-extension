<?php

declare(strict_types=1);

namespace Mugennsou\LaravelOctaneExtension\Amphp\Logger;

use Amp\ByteStream\WritableStream;
use Monolog\Handler\AbstractProcessingHandler;
use Psr\Log\LogLevel;

final class StreamHandler extends AbstractProcessingHandler
{
    private WritableStream $sink;

    /** @psalm-suppress ArgumentTypeCoercion */
    public function __construct(WritableStream $sink, string $level = LogLevel::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->sink = $sink;
    }

    protected function write(array $record): void
    {
        $this->sink->write(json_encode($record) . PHP_EOL);
    }
}
