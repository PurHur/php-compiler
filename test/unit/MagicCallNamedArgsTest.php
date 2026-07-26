<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * __call / __callStatic named args + named unpack (#23336).
 *
 * php-src: Zend/zend_object_handlers.c, Zend/zend_execute_API.c
 */
final class MagicCallNamedArgsTest extends TestCase
{
    public function testVmPacksNamedArgsIntoMagicCallArguments(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public function __call($n, $a) {
        echo "call $n ";
        var_export($a);
        echo "\n";
    }
    public static function __callStatic($n, $a) {
        echo "static $n ";
        var_export($a);
        echo "\n";
    }
}
$a = new A();
$a->bar(1, 2);
$a->qux(x: 1, y: 3);
A::qux(x: 1);
$a->qux(...['x' => 1, 'y' => 2]);
$a->qux(1, y: 3);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'magic_call_named.php'));
        $out = ob_get_clean();
        self::assertSame(
            "call bar array (\n  0 => 1,\n  1 => 2,\n)\n"
            ."call qux array (\n  'x' => 1,\n  'y' => 3,\n)\n"
            ."static qux array (\n  'x' => 1,\n)\n"
            ."call qux array (\n  'x' => 1,\n  'y' => 2,\n)\n"
            ."call qux array (\n  0 => 1,\n  'y' => 3,\n)\n",
            $out
        );
    }

    public function testVmRejectsDuplicateNamedInMagicCall(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public function __call($n, $a) {
        echo "unreachable\n";
    }
}
$a = new A();
try {
    $a->qux(x: 1, x: 2);
    echo "no error\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'magic_call_named_dup.php'));
        self::assertSame("Named parameter \$x overwrites previous argument\n", ob_get_clean());
    }
}
