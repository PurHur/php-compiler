<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on empty openssl-methods compiler ABI shells from Builtin\Type (#32451).
 *
 * User-script openssl_get_cipher_methods()/openssl_get_md_methods() stay PHP helpers.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * addFunction cannot mint name.1 (#31894 / #32122).
 */
final class TypeDeadOpensslMethodsAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_openssl_get_cipher_methods',
            '__compiler_openssl_get_md_methods',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnOpensslMethodsAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32451', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringNotContainsString(
                "addFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32451)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32451)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_openssl_pbkdf2'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_openssl_digest'", $type);
    }

    public function testRuntimeOwnerDeclaresOpensslMethodsAbisModuleLocally(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/OpensslMethodsRuntime.php');
        $this->assertStringContainsString("'__compiler_openssl_get_cipher_methods'", $runtime);
        $this->assertStringContainsString("'__compiler_openssl_get_md_methods'", $runtime);
        $this->assertStringContainsString('getNamedFunction(self::ABI_CIPHER)', $runtime);
        $this->assertStringContainsString('getNamedFunction(self::ABI_MD)', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
    }

    public function testNoNestedJitLookupOfDroppedOpensslMethodsAbisRemains(): void
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
                        || str_contains($source, "addFunction('{$sym}'")
                        || str_contains($source, 'addFunction("'.$sym.'"')) {
                        $hits[] = substr($path, strlen($root) + 1).':'.$sym;
                    }
                }
            }
        }
        $this->assertSame([], $hits, 'No NestedJIT lookup/add of dropped Type openssl-methods ABIs may remain (#32451)');
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../ext/openssl/JitOpensslMethods.php');
        $this->assertStringContainsString('OpensslMethodsCrypto::ensureLinked', $jit);
        $this->assertStringContainsString('openssl_get_cipher_methods', $jit);
        $this->assertStringContainsString('openssl_get_md_methods', $jit);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/openssl/OpensslMethodsJitHelper.php');
        $this->assertStringContainsString('OpensslCipherRegistry::CIPHER_METHODS', $helper);
        $this->assertStringContainsString('OpensslCipherRegistry::MD_METHODS', $helper);
    }
}
