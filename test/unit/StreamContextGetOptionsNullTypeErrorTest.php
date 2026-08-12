<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\stream_context_get_options;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #30418: null operand TypeError cites Zend stub name $stream_or_context. */
final class StreamContextGetOptionsNullTypeErrorTest extends TestCase
{
    public function testNullContextUsesStreamOrContextParamName(): void
    {
        $runtime = new Runtime();
        $builtin = new stream_context_get_options();
        $frame = $builtin->getFrame($runtime->vmContext);
        $null = new VMVariable();
        $null->null();
        $frame->calledArgs = [$null];
        $frame->returnVar = new VMVariable();

        try {
            $builtin->execute($frame);
            self::fail('expected TypeError');
        } catch (\TypeError $e) {
            self::assertStringContainsString('($stream_or_context)', $e->getMessage());
            self::assertStringNotContainsString('($context)', $e->getMessage());
        }
    }
}
