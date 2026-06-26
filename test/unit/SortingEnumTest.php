<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7229 */
final class SortingEnumTest extends TestCase
{
    public function testSortingBuiltinEnumExists(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('Sorting', false));
echo "\n";
var_export(Sorting::Ascending->name);
echo "\n";
var_export(Sorting::Ascending->value);
echo "\n";
var_export(Sorting::Descending->value);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'sorting_enum.php'));
        $this->assertSame("true\n'Ascending'\n4\n3", ob_get_clean());
    }

    public function testArrayMultisortAcceptsSortingEnumCases(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, Sorting::Ascending, $b);
echo implode(',', $a), "\n";
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, Sorting::Descending, $b);
echo implode(',', $a), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'sorting_multisort.php'));
        $this->assertSame("1,2,3\n3,2,1\n", ob_get_clean());
    }

    /** @covers issue #9947 */
    public function testSortAcceptsSortingEnumAndSortDirectionNamed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$a = [3, 1, 2];
sort($a, Sorting::Ascending);
echo implode(',', $a), "\n";
$b = [3, 1, 2];
sort($b, direction: SortDirection::Ascending);
echo implode(',', $b), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'sort_sorting_enum.php'));
        $this->assertSame("1,2,3\n1,2,3\n", ob_get_clean());
    }
}
