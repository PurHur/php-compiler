<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Ordinary `$c = $s[0]` must not share list-destruct lowering with `[$c] = $s` (#22646).
 */
final class StringOffsetAssignVsListDestructTest extends TestCase
{
    public function testVmStringOffsetAssignReturnsByte(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$s = 'AOT';
$c = $s[0];
echo $c, '|', strlen($c), '|', ord($c), "\n";
echo $s[0], '|', $s[1], '|', $s[2], "\n";
[$x] = $s;
echo 'list=', var_export($x, true), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'string_offset_vs_list.php'));
        self::assertSame(
            "A|1|65\nA|O|T\nlist=NULL\n",
            ob_get_clean()
        );
    }
}
