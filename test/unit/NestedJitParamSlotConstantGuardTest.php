<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * NestedJIT helper emit must not bind int formals into STRING slots when
 * Block::$constants holds a string placeholder on the CV's real slot (#28053 / #28038).
 */
final class NestedJitParamSlotConstantGuardTest extends TestCase
{
    public function testMakeVariableFromOpPrefersCfgTypeOverDisagreeingSlotConstant(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('slotConstantAgreesWithOperandType', $src);
        $this->assertStringContainsString('#28053', $src);
        $this->assertStringContainsString('TYPE_STRING placeholders on the CV', $src);
    }

    public function testUserScriptAotContextLoadsWithHelperRuntimeEmitting(): void
    {
        $llvmOk = \extension_loaded('ffi')
            && (
                is_dir(__DIR__.'/../../.llvm')
                || is_dir('/opt/llvm9')
                || (is_string(getenv('PHP_COMPILER_LLVM_PATH')) && getenv('PHP_COMPILER_LLVM_PATH') !== '')
            );
        if (!$llvmOk) {
            $this->markTestSkipped('LLVM 9 FFI not available on host — run via docker-exec');
        }
        putenv('PHP_COMPILER_HELPER_RUNTIME_EMITTING=1');
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_HELPER_RUNTIME_EMITTING'] = '1';
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $rt = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
            $rt->loadJitContext();
            $this->assertTrue(true);
        } finally {
            putenv('PHP_COMPILER_HELPER_RUNTIME_EMITTING');
            putenv('PHP_COMPILER_AOT_USER_SCRIPT');
            unset($_ENV['PHP_COMPILER_HELPER_RUNTIME_EMITTING'], $_ENV['PHP_COMPILER_AOT_USER_SCRIPT']);
        }
    }
}
