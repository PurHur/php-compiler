<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\VoidCastDesugar;
use PHPCompiler\CompilerVersion;
use PHPUnit\Framework\TestCase;

/** @covers issue #28441 #7346 #28183 */
final class VoidCastDesugarTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
    }

    public function testSupportsVoidCastOnProfile85Only(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.2');
        $this->assertFalse(CompilerVersion::supportsVoidCast());
        putenv('PHP_COMPILER_PROFILE=8.4');
        $this->assertFalse(CompilerVersion::supportsVoidCast());
        putenv('PHP_COMPILER_PROFILE=8.5');
        $this->assertTrue(CompilerVersion::supportsVoidCast());
    }

    public function testProfile85DesugarsStatementVoidCast(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $code = '<?php (void) f();';
        $this->assertSame(
            '<?php '.VoidCastDesugar::MARKER.'(f());',
            VoidCastDesugar::desugar($code)
        );
    }

    public function testProfile85LeavesAssignmentVoidCastUntouched(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $code = '<?php $x = (void)1;';
        $this->assertSame($code, VoidCastDesugar::desugar($code));
    }

    public function testProfile82LeavesVoidCastUntouched(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.2');
        $code = '<?php (void) f();';
        $this->assertSame($code, VoidCastDesugar::desugar($code));
    }

    public function testReturnTypeVoidUnchanged(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $code = '<?php function f(): void {}';
        $this->assertSame($code, VoidCastDesugar::desugar($code));
    }
}
