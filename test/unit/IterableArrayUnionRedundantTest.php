<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\RedundantIterableUnionCheck;
use PHPUnit\Framework\TestCase;

/** @covers issue #26564, #26591 */
final class IterableArrayUnionRedundantTest extends TestCase
{
    public function testIterableArrayUnionParamFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(iterable|array $x): string {
    return 't';
}
PHP;
        $block = $runtime->parseAndCompile($code, 'iterable_array_union_param.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantIterableUnionCheck::DUPLICATE_ARRAY_MESSAGE);
        $runtime->run($block, false);
    }

    public function testArrayIterableUnionReturnFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): array|iterable {
    return [];
}
PHP;
        $block = $runtime->parseAndCompile($code, 'array_iterable_union_return.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantIterableUnionCheck::DUPLICATE_ARRAY_MESSAGE);
        $runtime->run($block, false);
    }

    public function testIterableTraversableUnionParamFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(iterable|Traversable $x): string {
    return 't';
}
PHP;
        $block = $runtime->parseAndCompile($code, 'iterable_traversable_union_param.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantIterableUnionCheck::DUPLICATE_TRAVERSABLE_MESSAGE);
        $runtime->run($block, false);
    }

    public function testIterableTraversableArrayOrderMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(iterable|Traversable|array $x): string {
    return 't';
}
PHP;
        $block = $runtime->parseAndCompile($code, 'iterable_traversable_array.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantIterableUnionCheck::DUPLICATE_TRAVERSABLE_MESSAGE);
        $runtime->run($block, false);
    }

    public function testIterableArrayTraversableOrderMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(iterable|array|Traversable $x): string {
    return 't';
}
PHP;
        $block = $runtime->parseAndCompile($code, 'iterable_array_traversable.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantIterableUnionCheck::DUPLICATE_ARRAY_MESSAGE);
        $runtime->run($block, false);
    }

    public function testIterableArrayPropertyFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public iterable|array $x;
}
PHP;
        $block = $runtime->parseAndCompile($code, 'iterable_array_property.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantIterableUnionCheck::DUPLICATE_ARRAY_MESSAGE);
        $runtime->run($block, false);
    }

    public function testStandaloneIterableStillWorks(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(iterable $x): int {
    $n = 0;
    foreach ($x as $_) {
        $n++;
    }
    return $n;
}
echo f([1, 2]);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'standalone_iterable.php'));
        $this->assertSame('2', ob_get_clean());
    }

    public function testStandaloneArrayStillWorks(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(array $x): int {
    return count($x);
}
echo f([1]);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'standalone_array.php'));
        $this->assertSame('1', ob_get_clean());
    }

    public function testIterableStringUnionStillWorks(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(iterable|string $x): string {
    return is_string($x) ? $x : 'iter';
}
echo f('a');
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'iterable_string.php'));
        $this->assertSame('a', ob_get_clean());
    }

    public function testArrayTraversableWithoutIterableStillWorks(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(array|Traversable $x): string {
    return 'ok';
}
echo f([]);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'array_traversable.php'));
        $this->assertSame('ok', ob_get_clean());
    }
}
