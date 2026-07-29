<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #25024 */
final class MagicMethodArityCheckTest extends TestCase
{
    /**
     * @dataProvider invalidMagicArityProvider
     */
    public function testInvalidMagicMethodArityFailsAtCompileTime(string $code, string $message): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage($message);
        $runtime->parseAndCompile($code, 'invalid_magic_arity.php');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function invalidMagicArityProvider(): iterable
    {
        yield '__get zero' => [
            <<<'PHP'
<?php
class G { function __get() {} }
PHP,
            'Method G::__get() must take exactly 1 argument',
        ];
        yield '__set one' => [
            <<<'PHP'
<?php
class S { function __set($a) {} }
PHP,
            'Method S::__set() must take exactly 2 arguments',
        ];
        yield '__isset zero' => [
            <<<'PHP'
<?php
class I { function __isset() {} }
PHP,
            'Method I::__isset() must take exactly 1 argument',
        ];
        yield '__unset zero' => [
            <<<'PHP'
<?php
class U { function __unset() {} }
PHP,
            'Method U::__unset() must take exactly 1 argument',
        ];
        yield '__call one' => [
            <<<'PHP'
<?php
class C { function __call($a) {} }
PHP,
            'Method C::__call() must take exactly 2 arguments',
        ];
        yield '__callStatic one' => [
            <<<'PHP'
<?php
class CS { static function __callStatic($a) {} }
PHP,
            'Method CS::__callStatic() must take exactly 2 arguments',
        ];
        yield '__unserialize zero' => [
            <<<'PHP'
<?php
class Un { function __unserialize() {} }
PHP,
            'Method Un::__unserialize() must take exactly 1 argument',
        ];
        yield '__set_state zero' => [
            <<<'PHP'
<?php
class Ss { static function __set_state() {} }
PHP,
            'Method Ss::__set_state() must take exactly 1 argument',
        ];
        yield '__get variadic only' => [
            <<<'PHP'
<?php
class V { function __get(...$a) {} }
PHP,
            'Method V::__get() must take exactly 1 argument',
        ];
        yield '__destruct args' => [
            <<<'PHP'
<?php
class D { function __destruct($a) {} }
PHP,
            'Method D::__destruct() cannot take arguments',
        ];
        yield 'namespaced __get' => [
            <<<'PHP'
<?php
namespace N;
class G { function __get() {} }
PHP,
            'Method N\\G::__get() must take exactly 1 argument',
        ];
    }

    public function testValidMagicMethodArityCompilesAndRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Good {
    public function __get($n) { return $n; }
    public function __set($n, $v) {}
    public function __isset($n) { return false; }
    public function __unset($n) {}
    public function __call($n, $a) { return $n; }
    public static function __callStatic($n, $a) { return $n; }
    public function __unserialize($d) {}
    public static function __set_state($a) { return new self; }
    public function __destruct() {}
    public function __clone() {}
}
$g = new Good();
echo $g->missing, "\n";
echo $g->foo(), "\n";
echo Good::bar(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'valid_magic_arity.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("missing\nfoo\nbar\n", ob_get_clean());
    }

    public function testRequiredPlusVariadicCountsAsExactArity(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class V { function __get($a, ...$b) { return $a; } }
$v = new V();
echo $v->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'valid_magic_arity_variadic.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("x\n", ob_get_clean());
    }
}
