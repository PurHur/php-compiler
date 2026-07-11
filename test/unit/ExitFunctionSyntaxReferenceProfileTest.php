<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\ExitFunctionDesugar;
use PHPUnit\Framework\TestCase;

/** exit()/die() PHP 8.4 function-form reference profile gate (#13973). */
final class ExitFunctionSyntaxReferenceProfileTest extends TestCase
{
    public function testSupportsExitFunctionFormFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsExitFunctionForm());
    }

    public function testRejectorThrowsOnNamedStatusForm(): void
    {
        if (CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('exit function form enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(ExitFunctionDesugar::REFERENCE_PROFILE_UNEXPECTED_COLON);
        ExitFunctionSyntaxRejector::reject(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_exit_status_named_reference.php'),
            'exit_status_named.php'
        );
    }

    public function testRejectorThrowsOnNamedMessageForm(): void
    {
        if (CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('exit function form enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(ExitFunctionDesugar::REFERENCE_PROFILE_UNEXPECTED_COLON);
        ExitFunctionSyntaxRejector::reject('<?php die(message: "bye");', 'die_message.php');
    }

    public function testRejectorThrowsOnTwoArgForm(): void
    {
        if (CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('exit function form enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(ExitFunctionDesugar::REFERENCE_PROFILE_UNEXPECTED_COMMA);
        ExitFunctionSyntaxRejector::reject('<?php exit(1, "bye");', 'exit_two_arg.php');
    }

    public function testRejectorAllowsSinglePositionalArg(): void
    {
        if (CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('exit function form enabled on PHP 8.4.0+ target');
        }
        $code = '<?php exit(42);';
        $this->assertSame($code, ExitFunctionSyntaxRejector::reject($code, 'exit_pos.php'));
    }

    public function testDesugarNoOpWhenExitFunctionFormDisabled(): void
    {
        if (CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('exit function form enabled on PHP 8.4.0+ target');
        }
        $src = '<?php exit(status: 0);';
        $this->assertSame($src, ExitFunctionDesugar::desugar($src));
    }

    public function testRuntimeRejectsMaintainerGapRepro(): void
    {
        if (CompilerVersion::supportsExitFunctionForm()) {
            $this->markTestSkipped('exit function form enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_exit_status_named_reference.php'),
                'maintainer_gap_exit_status_named_reference.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(ExitFunctionDesugar::REFERENCE_PROFILE_UNEXPECTED_COLON, $e->getMessage());
        }
    }
}
