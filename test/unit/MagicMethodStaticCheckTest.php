<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #25026 #25027 */
final class MagicMethodStaticCheckTest extends TestCase
{
    /**
     * @dataProvider staticMagicProvider
     */
    public function testStaticMagicMethodFailsAtCompileTime(string $code, string $message): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage($message);
        $runtime->parseAndCompile($code, 'invalid_static_magic.php');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function staticMagicProvider(): iterable
    {
        yield '__sleep' => [
            <<<'PHP'
<?php
class Sl { static function __sleep() { return []; } }
PHP,
            'Method Sl::__sleep() cannot be static',
        ];
        yield '__wakeup' => [
            <<<'PHP'
<?php
class W { static function __wakeup() {} }
PHP,
            'Method W::__wakeup() cannot be static',
        ];
        yield '__invoke' => [
            <<<'PHP'
<?php
class I { static function __invoke() { return 1; } }
PHP,
            'Method I::__invoke() cannot be static',
        ];
        yield '__get' => [
            <<<'PHP'
<?php
class G { public static function __get($n) { return 1; } }
PHP,
            'Method G::__get() cannot be static',
        ];
        yield '__set' => [
            <<<'PHP'
<?php
class S { public static function __set($n, $v) {} }
PHP,
            'Method S::__set() cannot be static',
        ];
        yield '__isset' => [
            <<<'PHP'
<?php
class Is { public static function __isset($n) { return false; } }
PHP,
            'Method Is::__isset() cannot be static',
        ];
        yield '__unset' => [
            <<<'PHP'
<?php
class U { public static function __unset($n) {} }
PHP,
            'Method U::__unset() cannot be static',
        ];
        yield '__call' => [
            <<<'PHP'
<?php
class C { public static function __call($n, $a) {} }
PHP,
            'Method C::__call() cannot be static',
        ];
        yield '__toString' => [
            <<<'PHP'
<?php
class T { public static function __toString() { return "x"; } }
PHP,
            'Method T::__toString() cannot be static',
        ];
        yield 'namespaced __get' => [
            <<<'PHP'
<?php
namespace N;
class G { public static function __get($n) { return 1; } }
PHP,
            'Method N\\G::__get() cannot be static',
        ];
        yield 'namespaced __sleep' => [
            <<<'PHP'
<?php
namespace N;
class Sl { public static function __sleep() { return []; } }
PHP,
            'Method N\\Sl::__sleep() cannot be static',
        ];
    }

    public function testNonStaticMagicMethodsStillCompileAndRun(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Ok {
    public function __sleep() { return []; }
    public function __wakeup() {}
    public function __invoke() { return 7; }
    public function __get($n) { return 42; }
    public function __set($n, $v) {}
    public function __isset($n) { return true; }
    public function __unset($n) {}
    public function __call($n, $a) { return "call:$n"; }
    public function __toString() { return "str"; }
}
$o = new Ok();
echo $o(), "\n";
echo $o->missing, "\n";
echo $o->foo(), "\n";
echo (string) $o, "\n";
echo serialize($o), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'valid_static_magic.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("7\n42\ncall:foo\nstr\nO:2:\"Ok\":0:{}\n", ob_get_clean());
    }
}
