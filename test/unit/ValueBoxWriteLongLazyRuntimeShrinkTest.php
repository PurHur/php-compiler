<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Value::implement always-on __value__writeLong LLVM (#36135).
 *
 * Thin hello-world AOT must not emit long-box LLVM during Value init — first
 * lookupFunction('__value__writeLong') lazy-links (peer #36124 writeNull).
 *
 * SSOT: {@see \PHPCompiler\VM\VmValueBoxWriteLong} (php-src zend/zend_variables.c).
 */
final class ValueBoxWriteLongLazyRuntimeShrinkTest extends TestCase
{
    public function testValueImplementDropsEagerWriteLong(): void
    {
        foreach ([
            __DIR__.'/../../lib/JIT/Builtin/Type/Value.php',
            __DIR__.'/../../lib/JIT/Builtin/Type/Value.pre',
        ] as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString('#36135', $source);
            $pos = strpos($source, 'public function implement(): void');
            $this->assertNotFalse($pos);
            $next = strpos($source, 'public function initialize(): void', $pos);
            $this->assertNotFalse($next);
            $body = substr($source, $pos, $next - $pos);
            $this->assertStringNotContainsString(
                'implementValueWriteLong',
                $body,
                basename($path).' implement must not eagerly writeLong (#36135)'
            );
        }
    }

    public function testContextLookupFunctionLazyLinksWriteLong(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#36135', $context);
        $pos = strpos($context, 'public function lookupFunction');
        $this->assertNotFalse($pos);
        $next = strpos($context, 'public function tryGetRegisteredFunction', $pos);
        $this->assertNotFalse($next);
        $body = substr($context, $pos, $next - $pos);
        $this->assertStringContainsString("'__value__writeLong' === \$name", $body);
        $this->assertStringContainsString('ValueBoxWriteLongJit::ensureLinked', $body);
        $issetPos = strpos($body, 'isset($this->functionScope[$name])');
        $lazyPos = strpos($body, '__value__writeLong');
        $this->assertNotFalse($issetPos);
        $this->assertNotFalse($lazyPos);
        $this->assertLessThan($issetPos, $lazyPos, 'lazy hook must run before functionScope return (#36135)');
    }

    public function testValueBoxWriteLongJitEnsureLinkedIdempotent(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ValueBoxWriteLongJit.php');
        $this->assertStringContainsString('#36135', $jit);
        $this->assertStringContainsString('ensureLinked', $jit);
        $this->assertStringContainsString('countBasicBlocks()', $jit);
        $this->assertStringContainsString('NestedJitCompileScope::run', $jit);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $jit);
        $this->assertStringContainsString('VmValueBoxWriteLong::implement', $jit);
    }

    public function testVmValueBoxWriteLongImplementAvoidsLookupFunctionRecursion(): void
    {
        $vm = (string) file_get_contents(__DIR__.'/../../lib/VM/VmValueBoxWriteLong.php');
        $this->assertStringContainsString('#36135', $vm);
        $this->assertStringContainsString('tryGetRegisteredFunction', $vm);
        $pos = strpos($vm, 'public static function implement');
        $this->assertNotFalse($pos);
        $next = strpos($vm, 'private static function emitWriteLong', $pos);
        $this->assertNotFalse($next);
        $body = substr($vm, $pos, $next - $pos);
        $this->assertStringNotContainsString(
            'lookupFunction(',
            $body,
            'implement must not re-enter Context::lookupFunction (#36135 recursion)'
        );
    }

    public function testNoNewRuntimeCForLazyWriteLong(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'value_box.c',
            'phpc_value_box.c',
            'write_long.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                'must not add '.$name.' for #36135 — VmValueBoxWriteLong PHP only'
            );
        }
    }
}
