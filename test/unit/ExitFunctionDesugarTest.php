<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\ExitFunctionDesugar;
use PHPCompiler\CompilerVersion;
use PHPUnit\Framework\TestCase;

/** @covers issue #6975, #12413, #12435 */
final class ExitFunctionDesugarTest extends TestCase
{
    public function testTwoArgExitBecomesMarkerWhenEnabled(): void
    {
        if (!CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('exit function form disabled on reference profile');
        }
        $out = ExitFunctionDesugar::desugar('<?php exit(1, "bye");');
        $this->assertStringContainsString('__phpcExitCall(1, "bye")', $out);
    }

    public function testTwoArgDieBecomesMarkerWhenEnabled(): void
    {
        if (!CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('exit function form disabled on reference profile');
        }
        $out = ExitFunctionDesugar::desugar('<?php die(0, "ok");');
        $this->assertStringContainsString('__phpcDieCall(0, "ok")', $out);
    }

    public function testNamedArgExitBecomesMarkerWhenEnabled(): void
    {
        if (!CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('exit function form disabled on reference profile');
        }
        $out = ExitFunctionDesugar::desugar('<?php exit(status: 0);');
        $this->assertStringContainsString('__phpcExitCall(status: 0)', $out);
    }

    public function testFirstClassCallableExitBecomesMarkerWhenEnabled(): void
    {
        if (!CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('exit function form disabled on reference profile');
        }
        $out = ExitFunctionDesugar::desugar('<?php $fn = exit(...);');
        $this->assertStringContainsString('__phpcExitCall(...)', $out);
    }

    public function testBareExitUnchanged(): void
    {
        $code = '<?php exit; exit 1;';
        $this->assertSame($code, ExitFunctionDesugar::desugar($code));
    }

    public function testParenExitUnchangedOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('requires PHP 8.2 reference profile');
        }
        $code = '<?php exit(status: 0);';
        $this->assertSame($code, ExitFunctionDesugar::desugar($code));
    }

    public function testParenDieMessageUnchangedOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('requires PHP 8.2 reference profile');
        }
        $code = '<?php die(message: "bye");';
        $this->assertSame($code, ExitFunctionDesugar::desugar($code));
    }
}
