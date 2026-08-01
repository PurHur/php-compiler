<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issues #25026, #25027, #25028 */
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
        yield 'instance __callStatic' => [
            <<<'PHP'
<?php
class CS { public function __callStatic($n, $a) { return 1; } }
PHP,
            'Method CS::__callStatic() must be static',
        ];
        yield 'instance __set_state' => [
            <<<'PHP'
<?php
class Ss { public function __set_state($a) { return new self; } }
PHP,
            'Method Ss::__set_state() must be static',
        ];
        yield 'namespaced instance __callStatic' => [
            <<<'PHP'
<?php
namespace N;
class CS { function __callStatic($n, $a) { return 1; } }
PHP,
            'Method N\\CS::__callStatic() must be static',
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

    public function testMustBeStaticMagicMethodsStillCompileAndRun(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Ok {
    public static function __callStatic($n, $a) { return "cs:$n"; }
    public static function __set_state($a) { return new self; }
}
echo Ok::m(), "\n";
echo Ok::__set_state([]) instanceof Ok ? "ok\n" : "bad\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'valid_must_be_static_magic.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("cs:m\nok\n", ob_get_clean());
    }

    /** @covers issue #26437 — non-public __callStatic still dispatches (warning covered by compliance). */
    public function testNonPublicCallStaticDispatches(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
error_reporting(E_ALL);
class C {
    private static function __callStatic($n, $a) { return "cs:$n"; }
}
echo C::foo(), "\n";
PHP;
        ob_start();
        $block = $runtime->parseAndCompile($code, 'nonpublic_callstatic.php');
        $this->assertNotNull($block);
        $runtime->run($block);
        $this->assertSame("cs:foo\n", ob_get_clean());
    }

    /** @covers issue #26439 — non-public instance magics still dispatch (warning covered by compliance). */
    public function testNonPublicInstanceMagicDispatches(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
error_reporting(E_ALL);
class C {
    private function __get($n) { return "got:$n"; }
    private function __call($n, $a) { return "call:$n"; }
}
$o = new C;
echo $o->x, "\n";
echo $o->foo(), "\n";
PHP;
        ob_start();
        $block = $runtime->parseAndCompile($code, 'nonpublic_instance_magic.php');
        $this->assertNotNull($block);
        $runtime->run($block);
        $this->assertSame("got:x\ncall:foo\n", ob_get_clean());
    }

    /** @covers issue #26438 — non-public __invoke: $obj() ok, $obj->__invoke() fatals. */
    public function testNonPublicInvokeObjectCallDispatches(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
error_reporting(E_ALL);
class C {
    private function __invoke(): mixed { return 42; }
}
$o = new C;
echo $o(), "\n";
try {
    echo $o->__invoke(), "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $block = $runtime->parseAndCompile($code, 'nonpublic_invoke.php');
        $this->assertNotNull($block);
        $runtime->run($block);
        $this->assertSame(
            "42\nCall to private method C::__invoke() from global scope\n",
            ob_get_clean()
        );
    }
}
