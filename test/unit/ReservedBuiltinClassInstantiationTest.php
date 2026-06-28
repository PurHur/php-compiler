<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #13324 */
final class ReservedBuiltinClassInstantiationTest extends TestCase
{
    public function testNewClosureAndGeneratorThrowError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
foreach (['Closure', 'Generator'] as $class) {
    try {
        new $class();
        echo "fail: new {$class}() succeeded\n";
    } catch (Error $e) {
        echo $class, ':', $e::class, ':', $e->getMessage(), "\n";
    }
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reserved_new.php'));
        self::assertSame(
            "Closure:Error:Instantiation of class Closure is not allowed\n"
            ."Generator:Error:The \"Generator\" class is reserved for internal use and cannot be manually instantiated\n",
            ob_get_clean()
        );
    }
}
