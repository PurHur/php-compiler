<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class ArrayCowAssignTest extends TestCase
{
    public function testCopyOnWriteKeepsSourceAfterMutatingCopy(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$a = [1];
$c = $a;
$c[0] = 99;
var_dump($a, $c);
PHP;

        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'array_cow_simple.php'));
        self::assertSame(
            "array(1) {\n".
            "  [0]=>\n".
            "  int(1)\n".
            "}\n".
            "array(1) {\n".
            "  [0]=>\n".
            "  int(99)\n".
            "}\n",
            ob_get_clean()
        );
    }

    public function testCopyOnWriteWithReferenceStaysConsistent(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$a = [1];
$b = &$a;
$c = $a;
$c[0] = 99;
echo $a[0], ' ', $b[0], ' ', $c[0], "\n";
PHP;

        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'array_cow_ref.php'));
        self::assertSame("1 1 99\n", ob_get_clean());
    }
}
