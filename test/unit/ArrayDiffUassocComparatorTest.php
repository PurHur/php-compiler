<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class ArrayDiffUassocComparatorTest extends TestCase
{
    public function testKeyComparatorCaseInsensitive(): void
    {
        $code = <<<'PHP'
<?php
$a = ['a' => 1];
$b = ['A' => 1];
$cmp = static fn ($k1, $k2) => strcasecmp((string) $k1, (string) $k2);
var_export(array_diff_uassoc($a, $b, $cmp));
echo "\n";
var_export(array_intersect_uassoc($a, $b, $cmp));
echo "\n";
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'uassoc_cmp.php'));
        self::assertSame(
            "array (\n)\narray (\n  'a' => 1,\n)\n",
            ob_get_clean()
        );
    }
}
