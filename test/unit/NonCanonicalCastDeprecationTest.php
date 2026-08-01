<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ErrorLastJitHelper;
use PHPCompiler\ext\standard\NativeLastError;
use PHPCompiler\VM\ErrorReporter;
use PHPUnit\Framework\TestCase;

/** @covers \PHPCompiler\NonCanonicalCastDeprecation */
final class NonCanonicalCastDeprecationTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        parent::tearDown();
    }

    public function testEmitsAllFourAliasesUnderProfile85(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';
        $this->assertTrue(CompilerVersion::supportsNonCanonicalCastDeprecation());

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->errors->setErrorReporting(\E_ALL);
        NativeLastError::clear();

        NonCanonicalCastDeprecation::emitForSource(
            '<?php $a=(integer)1; $b=(boolean)1; $c=(double)1; $d=(binary)"x";',
            't.php',
            $ctx
        );

        $this->assertTrue(ErrorLastJitHelper::isActive());
        $this->assertSame(ErrorReporter::E_DEPRECATED, ErrorLastJitHelper::getType());
        $this->assertSame(
            'Non-canonical cast (binary) is deprecated, use the (string) cast instead',
            ErrorLastJitHelper::getMessage()
        );
    }

    public function testSkipsCanonicalCasts(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->errors->setErrorReporting(\E_ALL);
        NativeLastError::clear();

        NonCanonicalCastDeprecation::emitForSource(
            '<?php $a=(int)1; $b=(bool)1; $c=(float)1; $d=(string)"x";',
            't.php',
            $ctx
        );

        $this->assertFalse(ErrorLastJitHelper::isActive());
    }

    public function testSilentUnderProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->assertFalse(CompilerVersion::supportsNonCanonicalCastDeprecation());

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->errors->setErrorReporting(\E_ALL);
        NativeLastError::clear();

        NonCanonicalCastDeprecation::emitForSource(
            '<?php $a=(integer)1.5;',
            't.php',
            $ctx
        );

        $this->assertFalse(ErrorLastJitHelper::isActive());
    }

    public function testWhitespaceInsideCast(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->errors->setErrorReporting(\E_ALL);
        NativeLastError::clear();

        NonCanonicalCastDeprecation::emitForSource(
            '<?php $a=( integer )1;',
            't.php',
            $ctx
        );

        $this->assertTrue(ErrorLastJitHelper::isActive());
        $this->assertSame(
            'Non-canonical cast (integer) is deprecated, use the (int) cast instead',
            ErrorLastJitHelper::getMessage()
        );
    }
}
