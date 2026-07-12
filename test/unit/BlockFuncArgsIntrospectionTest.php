<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Call\Native;
use PHPUnit\Framework\TestCase;

/** Block::usesFuncArgsIntrospection gates CallArgv emit (#15907). */
final class BlockFuncArgsIntrospectionTest extends TestCase
{
    public function testFiboNativeCallSkipsCallArgv(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = new Runtime(Runtime::MODE_AOT);
        $path = $root.'/benchmarks/fibo(30).php';
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $fiboR = $this->funcBlockFromMain($block, 'fibo_r');
        self::assertFalse(Block::usesFuncArgsIntrospection($fiboR));

        $jit = new JIT($runtime->loadJitContext());
        $jit->compile($block);
        $proxy = $jit->context->functionProxies['fibo_r'] ?? null;
        self::assertInstanceOf(Native::class, $proxy);
        self::assertFalse($proxy->emitCallArgv);
    }

    public function testVariadicHelperNativeCallNeedsCallArgv(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile(
            '<?php declare(strict_types=1); function variadic_helper(int $a, ...$rest): void { func_get_args(); }',
            'variadic_helper.php'
        );
        $helper = $this->funcBlockFromMain($block, 'variadic_helper');
        self::assertTrue(Block::usesFuncArgsIntrospection($helper));

        $jit = new JIT($runtime->loadJitContext());
        $jit->compile($block);
        $proxy = $jit->context->functionProxies['variadic_helper'] ?? null;
        self::assertInstanceOf(Native::class, $proxy);
        self::assertTrue($proxy->emitCallArgv);
    }

    private function funcBlockFromMain(Block $main, string $name): Block
    {
        foreach ($main->opCodes as $op) {
            if (OpCode::TYPE_FUNCDEF === $op->type && $op->block1 instanceof Block) {
                $funcBlock = $op->block1;
                if (null !== $funcBlock->func && $name === $funcBlock->func->name) {
                    return $funcBlock;
                }
            }
        }
        self::fail('function block not found: '.$name);
    }
}
