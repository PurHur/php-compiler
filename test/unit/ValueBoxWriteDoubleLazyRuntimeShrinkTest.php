<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Value::implement always-on __value__writeDouble LLVM (#36141).
 *
 * Thin hello-world AOT must not emit double-box LLVM during Value init — first
 * lookupFunction('__value__writeDouble') lazy-links (peer #36135 writeLong).
 *
 * SSOT: {@see \PHPCompiler\VM\VmValueBoxWriteDouble} (php-src zend/zend_variables.c).
 */
final class ValueBoxWriteDoubleLazyRuntimeShrinkTest extends TestCase
{
    public function testValueImplementDropsEagerWriteDouble(): void
    {
        foreach ([
            __DIR__.'/../../lib/JIT/Builtin/Type/Value.php',
            __DIR__.'/../../lib/JIT/Builtin/Type/Value.pre',
        ] as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString('#36141', $source);
            $pos = strpos($source, 'public function implement(): void');
            $this->assertNotFalse($pos);
            $next = strpos($source, 'public function initialize(): void', $pos);
            $this->assertNotFalse($next);
            $body = substr($source, $pos, $next - $pos);
            $this->assertStringNotContainsString(
                'implementValueWriteDouble',
                $body,
                basename($path).' implement must not eagerly writeDouble (#36141)'
            );
        }
    }

    public function testContextLookupFunctionLazyLinksWriteDouble(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#36141', $context);
        $pos = strpos($context, 'public function lookupFunction');
        $this->assertNotFalse($pos);
        $next = strpos($context, 'public function tryGetRegisteredFunction', $pos);
        $this->assertNotFalse($next);
        $body = substr($context, $pos, $next - $pos);
        $this->assertStringContainsString("'__value__writeDouble' === \$name", $body);
        $this->assertStringContainsString('ValueBoxWriteDoubleJit::ensureLinked', $body);
        $issetPos = strpos($body, 'isset($this->functionScope[$name])');
        $lazyPos = strpos($body, '__value__writeDouble');
        $this->assertNotFalse($issetPos);
        $this->assertNotFalse($lazyPos);
        $this->assertLessThan($issetPos, $lazyPos, 'lazy hook must run before functionScope return (#36141)');
    }

    public function testValueBoxWriteDoubleJitEnsureLinkedIdempotent(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ValueBoxWriteDoubleJit.php');
        $this->assertStringContainsString('#36141', $jit);
        $this->assertStringContainsString('ensureLinked', $jit);
        $this->assertStringContainsString('countBasicBlocks()', $jit);
        $this->assertStringContainsString('NestedJitCompileScope::run', $jit);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $jit);
        $this->assertStringContainsString('VmValueBoxWriteDouble::implement', $jit);
    }

    public function testVmValueBoxWriteDoubleImplementAvoidsLookupFunctionRecursion(): void
    {
        $vm = (string) file_get_contents(__DIR__.'/../../lib/VM/VmValueBoxWriteDouble.php');
        $this->assertStringContainsString('#36141', $vm);
        $this->assertStringContainsString('tryGetRegisteredFunction', $vm);
        $pos = strpos($vm, 'public static function implement');
        $this->assertNotFalse($pos);
        $next = strpos($vm, 'private static function emitWriteDouble', $pos);
        $this->assertNotFalse($next);
        $body = substr($vm, $pos, $next - $pos);
        $this->assertStringNotContainsString(
            'lookupFunction(',
            $body,
            'implement must not re-enter Context::lookupFunction (#36141 recursion)'
        );
    }

    public function testNoNewRuntimeCForLazyWriteDouble(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'value_box.c',
            'phpc_value_box.c',
            'write_double.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                'must not add '.$name.' for #36141 — VmValueBoxWriteDouble PHP only'
            );
        }
    }
}
