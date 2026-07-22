<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ErrorLastJitHelper;
use PHPCompiler\ext\standard\NativeLastError;
use PHPCompiler\VM\ErrorReporter;
use PHPUnit\Framework\TestCase;

/** @covers \PHPCompiler\DollarBraceStringDeprecation */
final class DollarBraceStringDeprecationTest extends TestCase
{
    public function testEmitsOnDollarBraceToken(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->errors->setErrorReporting(\E_ALL);
        NativeLastError::clear();

        DollarBraceStringDeprecation::emitForSource(
            '<?php echo "${foo}";',
            't.php',
            $ctx
        );

        $this->assertTrue(ErrorLastJitHelper::isActive());
        $this->assertSame(ErrorReporter::E_DEPRECATED, ErrorLastJitHelper::getType());
        $this->assertSame(DollarBraceStringDeprecation::MESSAGE, ErrorLastJitHelper::getMessage());
        $this->assertSame('t.php', ErrorLastJitHelper::getFile());
        $this->assertSame(1, ErrorLastJitHelper::getLine());
    }

    public function testSkipsSimpleAndCurlyInterpolation(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->errors->setErrorReporting(\E_ALL);
        NativeLastError::clear();

        DollarBraceStringDeprecation::emitForSource('<?php echo "$foo";', 't.php', $ctx);
        DollarBraceStringDeprecation::emitForSource('<?php echo "{$foo}";', 't.php', $ctx);

        $this->assertFalse(ErrorLastJitHelper::isActive());
    }

    public function testSupportsGateIsOnForDefaultProfile(): void
    {
        $this->assertTrue(CompilerVersion::supportsDollarBraceStringDeprecation());
    }
}
