<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ErrorLastJitHelper;
use PHPCompiler\ext\standard\NativeLastError;
use PHPCompiler\VM\ErrorReporter;
use PHPUnit\Framework\TestCase;

/** @covers \PHPCompiler\SwitchCaseSemicolonDeprecation */
final class SwitchCaseSemicolonDeprecationTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        parent::tearDown();
    }

    public function testEmitsOnCaseAndDefaultSemicolonUnderProfile85(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';
        $this->assertTrue(CompilerVersion::supportsSwitchCaseSemicolonDeprecation());

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->errors->setErrorReporting(\E_ALL);
        NativeLastError::clear();

        SwitchCaseSemicolonDeprecation::emitForSource(
            '<?php switch (1) { case 1; echo 1; default; }',
            't.php',
            $ctx
        );

        $this->assertTrue(ErrorLastJitHelper::isActive());
        $this->assertSame(ErrorReporter::E_DEPRECATED, ErrorLastJitHelper::getType());
        $this->assertSame(SwitchCaseSemicolonDeprecation::MESSAGE, ErrorLastJitHelper::getMessage());
    }

    public function testSkipsColonFormAndEnumCases(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->errors->setErrorReporting(\E_ALL);
        NativeLastError::clear();

        SwitchCaseSemicolonDeprecation::emitForSource(
            '<?php switch (1) { case 1: echo 1; default: } enum E { case A; case B = 1; }',
            't.php',
            $ctx
        );

        $this->assertFalse(ErrorLastJitHelper::isActive());
    }

    public function testSilentUnderProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->assertFalse(CompilerVersion::supportsSwitchCaseSemicolonDeprecation());

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->errors->setErrorReporting(\E_ALL);
        NativeLastError::clear();

        SwitchCaseSemicolonDeprecation::emitForSource(
            '<?php switch (1) { case 1; echo 1; }',
            't.php',
            $ctx
        );

        $this->assertFalse(ErrorLastJitHelper::isActive());
    }
}
