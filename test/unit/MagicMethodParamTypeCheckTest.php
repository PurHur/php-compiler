<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26500 */
final class MagicMethodParamTypeCheckTest extends TestCase
{
    /**
     * @dataProvider invalidMagicParamTypeProvider
     */
    public function testInvalidMagicParamTypeFailsAtCompileTime(string $code, string $message): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage($message);
        $runtime->parseAndCompile($code, 'invalid_magic_param_type.php');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function invalidMagicParamTypeProvider(): iterable
    {
        yield '__get int' => [
            <<<'PHP'
<?php
class C { function __get(int $n) { return 1; } }
PHP,
            'C::__get(): Parameter #1 ($n) must be of type string when declared',
        ];
        yield '__set int name' => [
            <<<'PHP'
<?php
class C { function __set(int $n, $v): void {} }
PHP,
            'C::__set(): Parameter #1 ($n) must be of type string when declared',
        ];
        yield '__isset int' => [
            <<<'PHP'
<?php
class C { function __isset(int $n): bool { return false; } }
PHP,
            'C::__isset(): Parameter #1 ($n) must be of type string when declared',
        ];
        yield '__unset int' => [
            <<<'PHP'
<?php
class C { function __unset(int $n): void {} }
PHP,
            'C::__unset(): Parameter #1 ($n) must be of type string when declared',
        ];
        yield '__call int name' => [
            <<<'PHP'
<?php
class C { function __call(int $n, array $a) {} }
PHP,
            'C::__call(): Parameter #1 ($n) must be of type string when declared',
        ];
        yield '__call string args' => [
            <<<'PHP'
<?php
class C { function __call(string $n, string $a) {} }
PHP,
            'C::__call(): Parameter #2 ($a) must be of type array when declared',
        ];
        yield '__callStatic int name' => [
            <<<'PHP'
<?php
class C { static function __callStatic(int $n, array $a) {} }
PHP,
            'C::__callStatic(): Parameter #1 ($n) must be of type string when declared',
        ];
        yield '__callStatic int args' => [
            <<<'PHP'
<?php
class C { static function __callStatic(string $n, int $a) {} }
PHP,
            'C::__callStatic(): Parameter #2 ($a) must be of type array when declared',
        ];
        yield 'namespaced __get' => [
            <<<'PHP'
<?php
namespace N;
class C { function __get(array $n) { return 1; } }
PHP,
            'N\\C::__get(): Parameter #1 ($n) must be of type string when declared',
        ];
    }

    /**
     * @dataProvider validMagicParamTypeProvider
     */
    public function testValidMagicParamTypesCompile(string $code): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'valid_magic_param_type.php');
        $this->assertNotNull($block);
    }

    /** @return iterable<string, array{0: string}> */
    public static function validMagicParamTypeProvider(): iterable
    {
        yield 'untyped' => [
            <<<'PHP'
<?php
class G {
    public function __get($n) { return $n; }
    public function __set($n, $v) {}
    public function __isset($n) { return false; }
    public function __unset($n) {}
    public function __call($n, $a) { return $n; }
    public static function __callStatic($n, $a) { return $n; }
}
PHP,
        ];
        yield 'exact types' => [
            <<<'PHP'
<?php
class G {
    public function __get(string $n) { return $n; }
    public function __set(string $n, $v): void {}
    public function __isset(string $n): bool { return false; }
    public function __unset(string $n): void {}
    public function __call(string $n, array $a) { return $n; }
    public static function __callStatic(string $n, array $a) { return $n; }
}
PHP,
        ];
        yield 'nullable and unions and mixed' => [
            <<<'PHP'
<?php
class G {
    public function __get(?string $n) { return $n; }
    public function __set(string|int $n, mixed $v): void {}
    public function __isset(mixed $n): bool { return false; }
    public function __unset(string|null $n): void {}
    public function __call(string $n, iterable $a) { return $n; }
    public static function __callStatic(string $n, array|string $a) { return $n; }
}
PHP,
        ];
    }

    public function testValidSignaturesRun(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Good {
    public function __get(string $n) { return $n; }
    public function __call(string $n, array $a) { return $n; }
    public static function __callStatic(string $n, array $a) { return $n; }
}
$g = new Good();
echo $g->missing, "\n";
echo $g->foo(), "\n";
echo Good::bar(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'valid_magic_param_run.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame("missing\nfoo\nbar\n", ob_get_clean());
    }
}
