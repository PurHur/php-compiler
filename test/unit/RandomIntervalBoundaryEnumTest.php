<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #11551 */
final class RandomIntervalBoundaryEnumTest extends TestCase
{
    public function testIntervalBoundaryBuiltinEnumExists(): void
    {
        if (!CompilerVersion::supportsRandomIntervalBoundary()) {
            self::markTestSkipped('Random\\IntervalBoundary withheld on reference profile');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('Random\IntervalBoundary', false));
echo "\n";
var_export(unitenum_exists('Random\IntervalBoundary'));
echo "\n";
var_export(Random\IntervalBoundary::ClosedClosed->name);
echo "\n";
$case = Random\IntervalBoundary::OpenOpen;
var_export($case instanceof Random\IntervalBoundary);
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'random_interval_boundary_enum.php'));
        $this->assertSame("true\ntrue\n'ClosedClosed'\ntrue\n", ob_get_clean());
    }
}
