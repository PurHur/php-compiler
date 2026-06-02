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
            self::fail('expected TypeError');
        } catch (\TypeError $e) {
            self::assertSame('Cannot unpack array with string keys', $e->getMessage());
        }
    }
}
