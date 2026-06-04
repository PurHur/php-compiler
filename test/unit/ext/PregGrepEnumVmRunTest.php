<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** End-to-end VM run for preg_grep enum (#5639). */
final class PregGrepEnumVmRunTest extends TestCase
{
    public function testCompiledScriptThrowsErrorWhenReturnDiscarded(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'foo'; case B = 'bar'; }
try {
    preg_grep('/^f/', [E::A, E::B]);
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "Error: Object of class E could not be converted to string\n",
            $out
        );
    }
}
