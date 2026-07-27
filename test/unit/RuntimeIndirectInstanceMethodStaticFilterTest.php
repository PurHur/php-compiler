<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * RuntimeIndirect instance dispatch must skip FLAG_STATIC methods (#23468).
 *
 * HashTable::add and OutputRewriteVarsJitHelper::add share a short name; including
 * the static helper in class-id candidates made NestedJIT of VmPregMatches fail with
 * "argument 0 … must be a string" when Zend-compiling bin/compile.php.
 */
final class RuntimeIndirectInstanceMethodStaticFilterTest extends TestCase
{
    public function testBuildRuntimeCandidatesSkipsStaticMethods(): void
    {
        $jit = file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertNotFalse($jit);
        $this->assertStringContainsString(
            'buildRuntimeInstanceMethodCandidatesByClassId',
            $jit
        );
        $this->assertStringContainsString(
            'FLAG_STATIC',
            $jit
        );
        $this->assertStringContainsString(
            'OutputRewriteVarsJitHelper::add',
            $jit
        );
        $this->assertStringContainsString(
            'tryInitNestedVmHelperMethodCall($declaringClassLc, $methodLc, $receiverVar)',
            $jit
        );
    }

    public function testArgvDriverRefreshEnablesFullCliHostCompile(): void
    {
        $script = file_get_contents(
            dirname(__DIR__, 2).'/script/bootstrap-gen0-refresh-argv-driver.sh'
        );
        $this->assertNotFalse($script);
        $this->assertStringContainsString('PHP_COMPILER_M5_DRIVER_HOST=1', $script);
        $this->assertStringContainsString('PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1', $script);
        $this->assertStringContainsString('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1', $script);
        $this->assertStringContainsString('PHP_COMPILER_EMIT_HELPER_LINK=1', $script);
    }
}
