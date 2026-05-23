<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPUnit\Framework\TestCase;

final class TriggerErrorTest extends TestCase
{
    public function testUserErrorThrowsWhenReported(): void
    {
        $reporter = new ErrorReporter();
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('Fatal error: boom');
        $reporter->triggerError('boom', ErrorReporter::E_USER_ERROR);
    }

    public function testSuppressedWhenMaskedByErrorReporting(): void
    {
        $reporter = new ErrorReporter();
        $reporter->setErrorReporting(0);
        $reporter->triggerError('silent', ErrorReporter::E_USER_WARNING);
        $this->assertTrue(true);
    }

    public function testUnknownTypeIsIgnored(): void
    {
        $reporter = new ErrorReporter();
        $reporter->triggerError('noop', 99999);
        $this->assertTrue(true);
    }
}
