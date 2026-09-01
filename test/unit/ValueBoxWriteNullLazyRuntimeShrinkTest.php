<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Value::implement always-on __value__writeNull LLVM (#36124).
 *
 * Thin hello-world AOT must not emit null-box LLVM during Value init — first
 * lookupFunction('__value__writeNull') lazy-links (peer #36108 writeBool).
 *
 * SSOT: {@see \PHPCompiler\VM\VmValueBoxWriteNull} (php-src zend/zend_variables.c).
 */
final class ValueBoxWriteNullLazyRuntimeShrinkTest extends TestCase
{
    public function testValueImplementDropsEagerWriteNullJit(): void
    {
        foreach ([
            __DIR__.'/../../lib/JIT/Builtin/Type/Value.php',
            __DIR__.'/../../lib/JIT/Builtin/Type/Value.pre',
        ] as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString('#36124', $source);
            $pos = strpos($source, 'public function implement(): void');
            $this->assertNotFalse($pos);
            $next = strpos($source, 'public function initialize(): void', $pos);
            $this->assertNotFalse($next);
            $body = substr($source, $pos, $next - $pos);
            $this->assertStringNotContainsString(
                'implementValueWriteNull',
                $body,
                basename($path).' implement must not eagerly writeNull (#36124)'
            );
        }
    }

    public function testContextLookupFunctionLazyLinksWriteNull(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#36124', $context);
        $pos = strpos($context, 'public function lookupFunction');
        $this->assertNotFalse($pos);
        $next = strpos($context, 'public function tryGetRegisteredFunction', $pos);
        $this->assertNotFalse($next);
        $body = substr($context, $pos, $next - $pos);
        $this->assertStringContainsString("'__value__writeNull' === \$name", $body);
        $this->assertStringContainsString('ValueBoxWriteNullJit::ensureLinked', $body);
        $issetPos = strpos($body, 'isset($this->functionScope[$name])');
        $lazyPos = strpos($body, '__value__writeNull');
        $this->assertNotFalse($issetPos);
        $this->assertNotFalse($lazyPos);
        $this->assertLessThan($issetPos, $lazyPos, 'lazy hook must run before functionScope return (#36124)');
    }

    public function testValueBoxWriteNullJitEnsureLinkedIdempotent(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ValueBoxWriteNullJit.php');
        $this->assertStringContainsString('#36124', $jit);
        $this->assertStringContainsString('ensureLinked', $jit);
        $this->assertStringContainsString('countBasicBlocks()', $jit);
        $this->assertStringContainsString('NestedJitCompileScope::run', $jit);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $jit);
        $this->assertStringContainsString('VmValueBoxWriteNull::implement', $jit);
    }

    public function testVmValueBoxWriteNullImplementUsesValueDelrefThenStoreNullType(): void
    {
        $vm = (string) file_get_contents(__DIR__.'/../../lib/VM/VmValueBoxWriteNull.php');
        $this->assertStringContainsString('#36124', $vm);
        $this->assertStringContainsString('tryGetRegisteredFunction', $vm);
        $this->assertStringContainsString('__value__valueDelref', $vm);
        $this->assertStringContainsString('Variable::TYPE_NULL', $vm);
        $pos = strpos($vm, 'public static function implement');
        $this->assertNotFalse($pos);
        $next = strpos($vm, 'private static function emitWriteNull', $pos);
        $this->assertNotFalse($next);
        $body = substr($vm, $pos, $next - $pos);
        $this->assertStringNotContainsString(
            'lookupFunction(\'__value__writeNull\'',
            $body,
            'implement must not re-enter writeNull lookup (#36124 recursion)'
        );
    }

    public function testNoNewRuntimeCForLazyWriteNull(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'value_box.c',
            'phpc_value_box.c',
            'write_null.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                'must not add '.$name.' for #36124 — VmValueBoxWriteNull PHP only'
            );
        }
    }
}
