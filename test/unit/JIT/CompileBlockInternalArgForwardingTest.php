<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Guards compileBlockInternal() variadic arg forwarding (#1231 / #1238).
 *
 * After $startIndex / $allowRecompile were added, spread call sites must pass
 * explicit 0/false before ...$args or LLVM param Variables bind to $startIndex.
 */
final class CompileBlockInternalArgForwardingTest extends TestCase
{
    public function testUserMethodWithStaticCallCompilesForJit(): void
    {
        $src = <<<'PHP'
<?php
class Box {
    public static function size(): int {
        return 3;
    }
    public function doubled(): int {
        return static::size() * 2;
    }
}
echo (new Box())->doubled();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompileEmitSmoke($src, 'compile_block_internal_arg_forward.php');
        self::assertNotNull($block, 'JIT compile must not TypeError on compileBlockInternal arg #5');
    }

    public function testExtendsChainStaticCallCompilesForJit(): void
    {
        $src = <<<'PHP'
<?php
class Base {
    public static function who(): string {
        return static::class;
    }
}
class Child extends Base {
    public function name(): string {
        return static::who();
    }
}
echo (new Child())->name();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompileEmitSmoke($src, 'compile_block_internal_lsb_extends.php');
        self::assertNotNull($block, 'extends-chain static:: must compile without arg forwarding TypeError');
    }

    public function testParentStaticCallInExtendsChainCompilesForJit(): void
    {
        $src = <<<'PHP'
<?php
class Base {
    public static function id(): string {
        return 'Base';
    }
}
class Child extends Base {
    public function name(): string {
        return parent::id();
    }
}
echo (new Child())->name();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompileEmitSmoke($src, 'compile_block_internal_parent_extends.php');
        self::assertNotNull($block, 'extends-chain parent:: must compile without arg forwarding TypeError');
    }
}
