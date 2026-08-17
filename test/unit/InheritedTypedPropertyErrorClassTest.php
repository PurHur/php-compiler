<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Inherited typed-property Error names the declaring class (#31785).
 */
final class InheritedTypedPropertyErrorClassTest extends TestCase
{
    /**
     * @covers issue #31785
     */
    public function testInheritedUninitTypedPropertyErrorUsesDeclaringClass(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__) . '/repro/maintainer_gap_inherited_typed_error_class.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_inherited_typed_error_class.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "x=msg=Typed property A::\$x must not be accessed before initialization\n"
            ."after\n",
            $out
        );
    }

    /**
     * @covers issue #31785
     */
    public function testInheritedStaticAndRedeclaredTypedPropertyErrorClass(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public static int $s;
}
class B extends A {}
try {
    echo B::$s;
    echo "STATIC_OK\n";
} catch (\Throwable $e) {
    echo $e->getMessage(), "\n";
}
class P { public int $x; }
class C extends P { public int $x; }
try {
    echo (new C())->x;
    echo "REDECLARE_OK\n";
} catch (\Throwable $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'inherited_typed_static_redeclare.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "Typed static property A::\$s must not be accessed before initialization\n"
            ."Typed property C::\$x must not be accessed before initialization\n",
            $out
        );
    }
}
