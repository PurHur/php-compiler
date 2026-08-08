<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Sorting / SortDirection phantom retirement — SORT_* ints only (#28930, re-#12362). */
final class SortingEnumTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        parent::tearDown();
    }

    public function testSortingPhantomsAbsentOnProfile84(): void
    {
        $this->assertFalse(CompilerVersion::supportsSortingEnum());
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('Sorting', false));
echo "\n";
var_export(enum_exists('SortDirection', false));
echo "\n";
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, SORT_ASC, $b);
echo implode(',', $a), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'sorting_phantom.php'));
        $this->assertSame("false\nfalse\n1,2,3\n", ob_get_clean());
    }

    /** @covers issue #17429 #26142 — php-src never added SortDirection to usort* */
    public function testUserSortRejectsPhantomDirectionArity(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
foreach (['usort', 'uksort', 'uasort'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=n', $r->getNumberOfParameters(), "\n";
}
$a = [3, 1, 2];
try {
    usort($a, 'strcmp', 1);
    echo "positional=ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    usort(array: $a, callback: 'strcmp', direction: 1);
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
