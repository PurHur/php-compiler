<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

final class StrictTypesTest extends TestCase
{
    public function testStrictModeRejectsStringForIntParam(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
declare(strict_types=1);
function f(int $x) {
    return $x;
}
f('1');
PHP;
        $this->expectException(\TypeError::class);
        $runtime->run($runtime->parseAndCompile($code, 'strict_test.php'));
    }

    public function testWeakModeCoercesStringForIntParam(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(int $x) {
    return $x;
}
echo f('1');
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weak_test.php'));
        $out = ob_get_clean();
        $this->assertSame('1', $out);
    }
}
