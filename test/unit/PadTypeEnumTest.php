<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7282 */
final class PadTypeEnumTest extends TestCase
{
    public function testPadTypeBuiltinEnumAndStrPad(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('PadType', false));
echo "\n";
echo str_pad('hi', 5, ' ', 1), "\n";
echo str_pad('hi', 5, ' ', PadType::Right), "\n";
echo str_pad('hi', 5, ' ', PadType::Left), "\n";
echo str_pad('hi', 6, '-', PadType::Both), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'pad_type_enum.php'));
        $this->assertSame("true\nhi   \nhi   \n   hi\n--hi--\n", ob_get_clean());
    }
}
