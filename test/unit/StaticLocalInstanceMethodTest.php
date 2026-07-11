<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #12421 — static locals in instance methods persist across calls. */
final class StaticLocalInstanceMethodTest extends TestCase
{
    public function testStaticLocalIncrementsAcrossInstanceMethodCalls(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function f(): int {
        static $n = 0;
        return ++$n;
    }
}
$c = new C();
var_dump($c->f(), $c->f());
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'static_local_instance_method.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("int(1)\nint(2)\n", ob_get_clean());
    }

    public function testStaticLocalSharedAcrossInstances(): void
    {
        $code = file_get_contents(__DIR__.'/../compliance/cases/language/static_local_instance_method.phpt');
        self::assertIsString($code);
        if (!preg_match('/--FILE--\r?\n(.*)\r?\n--EXPECT--/s', $code, $m)) {
            self::fail('could not extract compliance FILE section');
        }
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($m[1], 'static_local_instance_method.phpt');
        ob_start();
        $runtime->run($block);
        self::assertSame("int(1)\nint(2)\nint(3)\nint(4)\nint(5)\n", ob_get_clean());
    }
}
