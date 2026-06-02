<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Runtime;

/** list() / [] destructuring from non-array RHS must leave targets unset (#4325). */
final class ListDestructNullTest extends TestCase
{
    public function testVmLeavesTargetsUnsetForNullFalseAndZero(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
[$a, $b] = null;
echo "a=", var_export($a, true), " b=", var_export($b, true), "\n";
list($x) = false;
echo "x=", var_export($x, true), "\n";
[$y] = 0;
echo "y=", var_export($y, true), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'list_destructure_null.php'));
        self::assertSame(
            "a=NULL b=NULL\nx=NULL\ny=NULL\n",
            ob_get_clean()
        );
    }
}
