<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Compile-time folded array literals must keep last duplicate key (Zend zend_hash.c; #4703). */
final class ArrayLiteralDuplicateKeyCompileTimeTest extends TestCase
{
    public function testClassConstDuplicateKeyLastWins(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public const A = ['a' => 1, 'a' => 2];
}
echo C::A['a'], "\n";
PHP;
        $this->assertSame("2\n", $this->runVm($runtime, $code));
    }

    public function testDefaultParamDuplicateKeyLastWins(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f($x = ['a' => 1, 'a' => 2]) {
    echo $x['a'], "\n";
}
f();
PHP;
        $this->assertSame("2\n", $this->runVm($runtime, $code));
    }

    private function runVm(Runtime $runtime, string $code): string
    {
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'array_literal_duplicate_key_compile_time.php'));
        $output = ob_get_clean();
        $this->assertIsString($output);

        return $output;
    }
}
