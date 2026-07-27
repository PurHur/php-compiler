<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class ListDestructKeyedByRefNestedTest extends TestCase
{
    public function testVmMatchesZendForKeyedByRefAndNestedDestructuring(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function rhs() {
  echo "rhs\n";
  return [1,2,3];
}

[$a, $b] = rhs();
var_dump($a, $b);

$list = ['x' => 10, 'y' => 20];
['y' => $yy, 'x' => $xx] = $list;
var_dump($xx, $yy);

$arr = [1,2];
[$r0, &$r1] = $arr;
$r1 = 999;
var_dump($arr);

[[ $n0 ], $n1] = [[7], 8];
var_dump($n0, $n1);
PHP;

        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'list_destruct_keyed_byref_nested.php'));
        self::assertSame(
            "rhs\n".
            "int(1)\n".
            "int(2)\n".
            "int(10)\n".
            "int(20)\n".
            "array(2) {\n".
            "  [0]=>\n".
            "  int(1)\n".
            "  [1]=>\n".
            "  &int(999)\n".
            "}\n".
            "int(7)\n".
            "int(8)\n",
            ob_get_clean()
        );
    }
}

