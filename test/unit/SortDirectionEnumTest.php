<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7261 */
final class SortDirectionEnumTest extends TestCase
{
    private function requireSortingEnum(): void
    {
        if (!CompilerVersion::supportsSortingEnum()) {
            $this->markTestSkipped('SortDirection enum withheld on reference profile');
        }
    }

    public function testSortDirectionBuiltinEnumExists(): void
    {
        $this->requireSortingEnum();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('SortDirection', false));
echo "\n";
var_export(unitenum_exists('SortDirection'));
echo "\n";
var_export(SortDirection::Ascending->name);
echo "\n";
var_export(SortDirection::Descending->name);
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'sortdirection_enum.php'));
        $this->assertSame("true\ntrue\n'Ascending'\n'Descending'\n", ob_get_clean());
    }

    public function testSortDirectionEnumAotLint(): void
    {
        $this->requireSortingEnum();
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/sortdirection_enum.php';
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for SortDirection enum probe (#7261)'
        );
    }
}
