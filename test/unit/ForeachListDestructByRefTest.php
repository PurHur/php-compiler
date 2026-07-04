<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #16213 */
final class ForeachListDestructByRefTest extends TestCase
{
    public function testListSyntaxWritesThroughToHaystack(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
$a = [[1]];
foreach ($a as list(&$v)) {
    $v = 2;
}
echo $a[0][0];
PHP, 'list_ref_foreach.php'));
        $this->assertSame('2', ob_get_clean());
    }

    public function testArrayDestructSyntaxWritesThroughToHaystack(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
$a = [[1, 2]];
foreach ($a as [$x, &$y]) {
    $y = 9;
}
echo $a[0][1];
PHP, 'foreach_array_destruct_ref.php'));
        $this->assertSame('9', ob_get_clean());
    }
}
