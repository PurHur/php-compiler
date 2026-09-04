<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Boxed float tags under strict_types: sqrt() args and `: float` returns (#36386).
 *
 * Value boxes store JIT TYPE_NATIVE_DOUBLE (=3); VmVariable::TYPE_FLOAT (=2) collides
 * with TYPE_NATIVE_BOOL and rejected real doubles (#20651).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(sqrt); Zend/zend_execute.c zend_verify_return_type.
 *
 * @group aot-lint
 */
final class StrictBoxedFloatSqrtReturnAotTest extends TestCase
{
    public function testPropertyDerivedSqrtUnderStrictMatchesZend(): void
    {
        $src = file_get_contents(__DIR__.'/../repro/i36386_strict_sqrt_property_float.php');
        $this->assertNotFalse($src);
        $path = sys_get_temp_dir().'/phpc_strict_sqrt_prop_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_strict_sqrt_prop_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zendOut, $zendRc);
            $this->assertSame(0, $zendRc);
            exec(escapeshellarg($bin), $aotOut, $aotRc);
            $this->assertSame(0, $aotRc, 'AOT must not TypeError/exit early on boxed double sqrt');
            $this->assertSame($zendOut, $aotOut);
            $this->assertCount(1, $aotOut);
            $this->assertEqualsWithDelta(6.3417978523444, (float) $aotOut[0], 1e-12);
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testTypedFloatReturnOfBoxedAccumMatchesZend(): void
    {
        $src = file_get_contents(__DIR__.'/../repro/i36386_strict_float_return_accum.php');
        $this->assertNotFalse($src);
        $path = sys_get_temp_dir().'/phpc_strict_fret_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_strict_fret_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zendOut, $zendRc);
            $this->assertSame(0, $zendRc);
            exec(escapeshellarg($bin), $aotOut, $aotRc);
            $this->assertSame(0, $aotRc, 'AOT must return boxed double as : float');
            $this->assertSame($zendOut, $aotOut);
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testInternalStrictArgChecksNativeDoubleTag(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/InternalStrictArg.php');
        $this->assertStringContainsString('TYPE_NATIVE_DOUBLE', $source);
        $this->assertStringContainsString('#20651', $source);
        // enforceFloatValueBox must not key off VmVariable::TYPE_FLOAT alone.
        $this->assertMatchesRegularExpression(
            '/function enforceFloatValueBox.*?TYPE_NATIVE_DOUBLE/s',
            $source
        );
    }

    public function testScalarReturnCheckAcceptsNativeDoubleTag(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ScalarReturnCheck.php');
        $this->assertMatchesRegularExpression(
            '/TYPE_NATIVE_DOUBLE === \$expectedJit.*?TYPE_NATIVE_DOUBLE/s',
            $source
        );
    }
}
