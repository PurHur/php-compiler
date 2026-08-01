<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ErrorLastJitHelper;
use PHPCompiler\ext\standard\NativeLastError;
use PHPCompiler\VM\ErrorReporter;
use PHPUnit\Framework\TestCase;

/** @covers \PHPCompiler\BacktickShellExecDeprecation */
final class BacktickShellExecDeprecationTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        parent::tearDown();
    }

    public function testEmitsOnBacktickUnderProfile85(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';
        $this->assertTrue(CompilerVersion::supportsBacktickShellExecDeprecation());

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->errors->setErrorReporting(\E_ALL);
        NativeLastError::clear();

        BacktickShellExecDeprecation::emitForSource(
            '<?php $out = `true`;',
            't.php',
            $ctx
        );

        $this->assertTrue(ErrorLastJitHelper::isActive());
        $this->assertSame(ErrorReporter::E_DEPRECATED, ErrorLastJitHelper::getType());
        $this->assertSame(BacktickShellExecDeprecation::MESSAGE, ErrorLastJitHelper::getMessage());
    }

    public function testSkipsShellExecCallAndStringLiterals(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->errors->setErrorReporting(\E_ALL);
        NativeLastError::clear();

        BacktickShellExecDeprecation::emitForSource(
            '<?php $a = "`not`"; $b = shell_exec("true"); // `comment`',
            't.php',
            $ctx
        );

        $this->assertFalse(ErrorLastJitHelper::isActive());
    }

    public function testSilentUnderProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->assertFalse(CompilerVersion::supportsBacktickShellExecDeprecation());

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->errors->setErrorReporting(\E_ALL);
        NativeLastError::clear();

        BacktickShellExecDeprecation::emitForSource(
            '<?php $out = `true`;',
            't.php',
            $ctx
        );

        $this->assertFalse(ErrorLastJitHelper::isActive());
    }
}
