<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7229 */
final class SortingEnumTest extends TestCase
{
    private function requireSortingEnum(): void
    {
        if (!CompilerVersion::supportsSortingEnum()) {
            $this->markTestSkipped('Sorting enum withheld on reference profile');
        }
    }

    public function testSortingBuiltinEnumExists(): void
    {
        $this->requireSortingEnum();
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
        $this->requireSortingEnum();
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
    public function testSortAcceptsSortingEnumAsFlags(): void
    {
        $this->requireSortingEnum();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$a = [3, 1, 2];
sort($a, Sorting::Ascending);
echo implode(',', $a), "\n";
$b = [3, 1, 2];
sort($b, flags: Sorting::Ascending);
echo implode(',', $b), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'sort_sorting_enum.php'));
        $this->assertSame("1,2,3\n1,2,3\n", ob_get_clean());
    }

    /** @covers issue #17429 #26142 — php-src never added SortDirection to usort* */
    public function testUserSortRejectsPhantomSortDirection(): void
    {
        $this->requireSortingEnum();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
foreach (['usort', 'uksort', 'uasort'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=n', $r->getNumberOfParameters(), "\n";
}
$a = [3, 1, 2];
try {
    usort($a, 'strcmp', SortDirection::Ascending);
    echo "positional=ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    usort(array: $a, callback: 'strcmp', direction: SortDirection::Ascending);
    echo "named=ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'usort_phantom_direction.php'));
        $out = ob_get_clean();
        $this->assertStringContainsString("usort=n2\n", $out);
        $this->assertStringContainsString("uksort=n2\n", $out);
        $this->assertStringContainsString("uasort=n2\n", $out);
        $this->assertStringContainsString("ArgumentCountError\n", $out);
        $this->assertStringContainsString('Unknown named parameter $direction', $out);
        $this->assertStringNotContainsString('positional=ok', $out);
        $this->assertStringNotContainsString('named=ok', $out);
    }
}
