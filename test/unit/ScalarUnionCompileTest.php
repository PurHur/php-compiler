<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6833 */
final class ScalarUnionCompileTest extends TestCase
{
    public function test_scalar_union_param_property_and_return_compile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
declare(strict_types=1);
function f(int|string $x): int|string {
    return $x;
}
echo f(1), f('z');

class C {
    public int|string $p;
}
$c = new C();
$c->p = 'ok';
echo $c->p;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'scalar_union.php'));
        $this->assertSame('1zok', ob_get_clean());
    }

    public function test_scalar_union_uninitialized_property_read_throws_error(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public int|string $p;
}
$c = new C();
try {
    echo $c->p;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'scalar_union_uninit.php'));
        $this->assertSame(
            "Typed property C::\$p must not be accessed before initialization\n",
            ob_get_clean()
        );
    }
}
