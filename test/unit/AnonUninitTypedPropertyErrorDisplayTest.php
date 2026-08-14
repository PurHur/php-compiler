<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Uninit typed-property Error for anonymous classes must strip NUL+filepath (#31117 / re-#29267).
 */
final class AnonUninitTypedPropertyErrorDisplayTest extends TestCase
{
    /**
     * @covers issue #31117
     */
    public function testAnonymousUninitTypedPropertyErrorUsesZendDisplayName(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$c = new class { public int $x; };
try {
    echo $c->x;
    echo "UNEXPECTED_OK\n";
} catch (\Throwable $e) {
    $m = $e->getMessage();
    echo str_contains($m, "\0") ? "HAS_NUL\n" : "NO_NUL\n";
    echo $m, "\n";
}
echo str_contains(get_class($c), "\0") ? "GET_CLASS_HAS_NUL\n" : "GET_CLASS_NO_NUL\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'anon_uninit_typed_property.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "NO_NUL\n"
            ."Typed property class@anonymous::\$x must not be accessed before initialization\n"
            ."GET_CLASS_HAS_NUL\n",
            $out
        );
    }

    /**
     * @covers issue #31117
     */
    public function testNamedUninitTypedPropertyErrorUnchanged(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public int $x;
}
try {
    echo (new C())->x;
    echo "UNEXPECTED_OK\n";
} catch (\Throwable $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'named_uninit_typed_property.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "Typed property C::\$x must not be accessed before initialization\n",
            $out
        );
    }
}
