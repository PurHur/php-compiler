<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Call-time ...$iter spread must accept Generator / Traversable operands (#4452). */
final class CallUnpackTraversableTest extends TestCase
{
    public function testVmUnpacksGenerator(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function sum(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
$gen = (function (): Generator {
    yield 1;
    yield 2;
    yield 3;
})();
echo sum(...$gen);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_generator.php'));
        self::assertSame('6', ob_get_clean());
    }

    /** Fresh Generator from a call expression must not drop the opening yield (#24646). */
    public function testVmUnpacksGeneratorCallExpression(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function g(): Generator { yield 1; yield 2; yield 3; }
function sum(int $a, int $b, int $c): int { return $a + $b + $c; }
echo sum(...g());
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_generator_call.php'));
        self::assertSame('6', ob_get_clean());
    }

    public function testVmUnpacksIteratorAggregate(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class GenAgg implements IteratorAggregate {
    public function getIterator(): Generator {
        yield 4;
        yield 5;
        yield 6;
    }
}
function sum(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
echo sum(...(new GenAgg()));
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_iterator_aggregate.php'));
        self::assertSame('15', ob_get_clean());
    }

    /** IteratorAggregate yielding two values — first must not be skipped (#24646). */
    public function testVmUnpacksIteratorAggregatePair(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class PairAgg implements IteratorAggregate {
    public function getIterator(): Generator {
        yield 1;
        yield 2;
    }
}
function s(int $a, int $b): int { return $a + $b; }
echo s(...(new PairAgg()));
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_iterator_aggregate_pair.php'));
        self::assertSame('3', ob_get_clean());
    }

    /** Named yield keys from a Generator bind as named call args (#24646). */
    public function testVmUnpacksGeneratorNamedKeys(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function s(int $a, int $b): void { echo "$a,$b"; }
$gen = (function (): Generator {
    yield 'a' => 1;
    yield 'b' => 2;
})();
s(...$gen);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_generator_named.php'));
        self::assertSame('1,2', ob_get_clean());
    }

    public function testVmRejectsGeneratorWithStringKeys(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function sum(int $a, int $b): int {
    return $a + $b;
}
$gen = (function (): Generator {
    yield 'x' => 1;
    yield 2;
})();
sum(...$gen);
PHP;
        try {
            $runtime->run($runtime->parseAndCompile($code, 'call_unpack_gen_string_keys.php'));
            self::fail('expected Error');
        } catch (\Error $e) {
            self::assertSame('Unknown named parameter $x', $e->getMessage());
        }
    }
}
