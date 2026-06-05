<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** CompilerVersion gates for PHP 8.3+ surface (#5697, #5212, #5993). */
final class CompilerVersionGateTest extends TestCase
{
    public function testVersionReports83Dev(): void
    {
        $this->assertSame('8.3.0-dev', CompilerVersion::VERSION);
    }

    public function testSupportsStrIncrementTrueOn83Target(): void
    {
        $this->assertTrue(CompilerVersion::supportsStrIncrement());
    }

    public function testSupportsTypedTraitConstantsTrueOn83Target(): void
    {
        $this->assertTrue(CompilerVersion::supportsTypedTraitConstants());
    }

    public function testVmRegistersStrIncrementOn83Target(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertTrue(isset($ctx->functions['str_decrement']));
        $this->assertTrue(isset($ctx->functions['str_increment']));
    }
}
