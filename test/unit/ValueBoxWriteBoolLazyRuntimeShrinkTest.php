<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Value::implement always-on __value__writeBool LLVM (#36108).
 *
 * Thin hello-world AOT must not emit bool-box LLVM during Value init — first
 * lookupFunction('__value__writeBool') lazy-links (peer #36100 malloc).
 *
 * SSOT: {@see \PHPCompiler\VM\VmValueBoxWriteBool} (replaces phpc_value_box.c bool writer).
 */
final class ValueBoxWriteBoolLazyRuntimeShrinkTest extends TestCase
{
    public function testValueImplementDropsEagerWriteBoolJit(): void
    {
        foreach ([
            __DIR__.'/../../lib/JIT/Builtin/Type/Value.php',
            __DIR__.'/../../lib/JIT/Builtin/Type/Value.pre',
        ] as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString('#36108', $source);
            $pos = strpos($source, 'public function implement(): void');
            $this->assertNotFalse($pos);
            $next = strpos($source, 'public function initialize(): void', $pos);
            $this->assertNotFalse($next);
            $body = substr($source, $pos, $next - $pos);
            $this->assertStringNotContainsString(
                'ValueBoxWriteBoolJit::implement',
                $body,
                basename($path).' implement must not eagerly ValueBoxWriteBoolJit (#36108)'
            );
        }
    }

    public function testContextLookupFunctionLazyLinksWriteBool(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#36108', $context);
        $pos = strpos($context, 'public function lookupFunction');
        $this->assertNotFalse($pos);
        $next = strpos($context, 'public function tryGetRegisteredFunction', $pos);
        $this->assertNotFalse($next);
        $body = substr($context, $pos, $next - $pos);
        $this->assertStringContainsString("'__value__writeBool' === \$name", $body);
        $this->assertStringContainsString('ValueBoxWriteBoolJit::ensureLinked', $body);
        $issetPos = strpos($body, 'isset($this->functionScope[$name])');
        $lazyPos = strpos($body, '__value__writeBool');
        $this->assertNotFalse($issetPos);
        $this->assertNotFalse($lazyPos);
        $this->assertLessThan($issetPos, $lazyPos, 'lazy hook must run before functionScope return (#36108)');
    }

    public function testValueBoxWriteBoolJitEnsureLinkedIdempotent(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ValueBoxWriteBoolJit.php');
        $this->assertStringContainsString('#36108', $jit);
        $this->assertStringContainsString('ensureLinked', $jit);
        $this->assertStringContainsString('countBasicBlocks()', $jit);
        $this->assertStringContainsString('NestedJitCompileScope::run', $jit);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $jit);
        $this->assertStringContainsString('VmValueBoxWriteBool::implement', $jit);
    }

    public function testVmValueBoxWriteBoolImplementAvoidsLookupFunctionRecursion(): void
    {
        $vm = (string) file_get_contents(__DIR__.'/../../lib/VM/VmValueBoxWriteBool.php');
        $this->assertStringContainsString('#36108', $vm);
        $this->assertStringContainsString('tryGetRegisteredFunction', $vm);
        $pos = strpos($vm, 'public static function implement');
        $this->assertNotFalse($pos);
        $next = strpos($vm, 'private static function emitWriteBool', $pos);
        $this->assertNotFalse($next);
        $body = substr($vm, $pos, $next - $pos);
        $this->assertStringNotContainsString(
            'lookupFunction(',
            $body,
            'implement must not re-enter Context::lookupFunction (#36108 recursion)'
        );
    }

    public function testNoNewRuntimeCForLazyWriteBool(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'value_box.c',
            'phpc_value_box.c',
            'write_bool.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                'must not add '.$name.' for #36108 — VmValueBoxWriteBool PHP only'
            );
        }
    }
}
