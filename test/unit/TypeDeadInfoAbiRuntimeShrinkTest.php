<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on info ABI shells from Builtin\Type (#32839).
 *
 * User-script phpversion()/php_sapi_name()/zend_version()/php_uname()/
 * extension_loaded()/get_loaded_extensions()/get_extension_funcs() stay PHP helpers.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadInfoAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_phpversion',
            '__compiler_php_sapi_name',
            '__compiler_zend_version',
            '__compiler_php_uname',
            '__compiler_extension_loaded',
            '__compiler_get_loaded_extensions',
            '__compiler_get_extension_funcs',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnInfoAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32839', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32839)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32839)"
            );
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $type,
                "Builtin\\Type must not always-declare {$sym} in a table (#32839)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_proc_open'", $type);
        $this->assertStringContainsString('StringInfo::ensureLinked', $type);
        $this->assertStringContainsString('StringVersionCompare::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresInfoAbisModuleLocally(): void
    {
        $info = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringInfo.php');
        $this->assertStringContainsString('#32839', $info);
        $this->assertStringContainsString("getNamedFunction('__compiler_php_sapi_name')", $info);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $info);
        }
        $this->assertStringContainsString('module->addFunction(', $info);
    }

    public function testTypeInitializeStillEnsureLinksStringInfo(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringInfo::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitInfo.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/InfoJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/VmInfo.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_info.c'
        );
    }
}
