<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on dead compiler ABI shells from Builtin\Type (#32250).
 *
 * User-script substr_replace()/idate()/getdate()/file()/xmlrpc_encode() stay PHP/IR.
 * NestedJIT has no lookup of these five names.
 */
final class TypeDeadCompilerAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_substr_replace',
            '__compiler_idate',
            '__compiler_getdate',
            '__phpc_file_vec',
            '__compiler_xmlrpc_encode_array',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnDeadCompilerAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32250', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32250)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32250)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_xmlrpc_encode_value'", $type);
        $this->assertStringContainsString("registerFunction('__phpc_glob_vec'", $type);
        $this->assertStringContainsString("registerFunction('__phpc_scandir_vec'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_localtime'", $type);
    }

    public function testNoNestedJitLookupOfDroppedCompilerAbisRemains(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        foreach (['lib', 'ext'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                $path = $file->getPathname();
                if (str_ends_with($path, '/lib/JIT/Builtin/Type.php')) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                foreach ($this->droppedAbis() as $sym) {
                    if (str_contains($source, "lookupFunction('{$sym}')")
                        || str_contains($source, 'lookupFunction("'.$sym.'")')
                        || str_contains($source, "getNamedFunction('{$sym}')")
                        || str_contains($source, 'getNamedFunction("'.$sym.'")')
                        || str_contains($source, "addFunction('{$sym}'")
                        || str_contains($source, 'addFunction("'.$sym.'"')) {
                        $hits[] = substr($path, strlen($root) + 1).':'.$sym;
                    }
                }
            }
        }
        $this->assertSame([], $hits, 'No NestedJIT lookup/getNamed/add of dropped Type ABIs may remain (#32250)');
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'function substr_replace',
            (string) file_get_contents(__DIR__.'/../../ext/standard/VmString.php')
        );
        $this->assertStringContainsString(
            'IdateJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitIdate.php')
        );
        $this->assertStringContainsString(
            'GetdateJitHelper',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetdate.php')
        );
        $this->assertStringContainsString(
            '__compiler_xmlrpc_encode_value',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringXmlrpc.php')
        );
        $this->assertStringNotContainsString(
            '__compiler_xmlrpc_encode_array',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringXmlrpc.php')
        );
        $stringIdate = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringIdate.php');
        $this->assertStringContainsString('Intentionally empty', $stringIdate);
        $this->assertStringNotContainsString('__compiler_idate', $stringIdate);
        $stringGetdate = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetdate.php');
        $this->assertStringContainsString('Intentionally empty', $stringGetdate);
        $this->assertStringNotContainsString('__compiler_getdate', $stringGetdate);
    }
}
