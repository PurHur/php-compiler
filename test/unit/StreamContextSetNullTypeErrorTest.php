<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\stream_context_set_option;
use PHPCompiler\ext\standard\stream_context_set_options;
use PHPCompiler\ext\standard\stream_context_set_params;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #19213: null stream context on by-ref setters must TypeError before by-ref bind. */
final class StreamContextSetNullTypeErrorTest extends TestCase
{
    /**
     * @dataProvider setterProvider
     */
    public function testNullContextTypeError(string $class): void
    {
        $runtime = new Runtime();
        $builtin = new $class();
        $frame = $builtin->getFrame($runtime->vmContext);
        $null = new VMVariable();
        $null->null();
        $options = new VMVariable();
        $options->newArray();
        $frame->calledArgs = [$null, $options];
        $frame->returnVar = new VMVariable();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('must be of type resource');

        $builtin->execute($frame);
    }

    /**
     * @return list<array{class-string}>
     */
    public static function setterProvider(): array
    {
        return [
            [stream_context_set_option::class],
            [stream_context_set_options::class],
            [stream_context_set_params::class],
        ];
    }
}
