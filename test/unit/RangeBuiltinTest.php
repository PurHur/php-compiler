<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #17427 */
final class RangeBuiltinTest extends TestCase
{
    private function requireRange(): void
    {
        if (!CompilerVersion::supportsRange()) {
            $this->markTestSkipped('Range withheld on reference profile');
        }
    }

    public function testRangeFromIteratesIntAndString(): void
    {
        $this->requireRange();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$intParts = [];
foreach (Range::from(1, 3) as $i) {
    $intParts[] = $i;
}
$stringParts = [];
foreach (Range::from('a', 'c') as $c) {
    $stringParts[] = $c;
}
var_export($intParts);
echo "\n";
var_export($stringParts);
echo "\n";
var_export(class_exists('Range', false));
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'range_from.php'));
        $this->assertSame(
            "array (\n  0 => 1,\n  1 => 2,\n  2 => 3,\n)\narray (\n  0 => 'a',\n  1 => 'b',\n  2 => 'c',\n)\ntrue\n",
            ob_get_clean()
        );
    }
}
