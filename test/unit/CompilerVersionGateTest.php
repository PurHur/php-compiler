<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** CompilerVersion gates for PHP 8.3+ surface on 8.2 target (issue #5697, #5212). */
final class CompilerVersionGateTest extends TestCase
{
    public function testVersionReports82Dev(): void
    {
        $this->assertSame('8.2.0-dev', CompilerVersion::VERSION);
    }

    public function testSupportsStrIncrementFalseOn82Target(): void
    {
        $this->assertFalse(CompilerVersion::supportsStrIncrement());
    }

    public function testSupportsTypedTraitConstantsFalseOn82Target(): void
    {
        $this->assertFalse(CompilerVersion::supportsTypedTraitConstants());
    }

    public function testVmDoesNotRegisterStrDecrementOn82Target(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->functions['str_decrement']));
        $this->assertFalse(isset($ctx->functions['str_increment']));
    }
}
