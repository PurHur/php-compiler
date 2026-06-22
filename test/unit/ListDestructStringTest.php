<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** list() / [] destructuring from string RHS leaves targets NULL (#10486). */
final class ListDestructStringTest extends TestCase
{
    public function testVmLeavesTargetsNullForStringRhs(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
list($a, $b) = 'ab';
echo "a=", var_export($a, true), " b=", var_export($b, true), "\n";
[$x] = 'xy';
echo "x=", var_export($x, true), "\n";
[[ $y ]] = 'z';
echo "y=", var_export($y, true), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'list_destructure_string.php'));
        self::assertSame(
            "a=NULL b=NULL\nx=NULL\ny=NULL\n",
            ob_get_clean()
        );
    }
}
