<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\BuiltinStubEnumTestSkip;
use PHPUnit\Framework\TestCase;

/** @covers issue #7283 */
final class StringTrimModeEnumTest extends TestCase
{
    use BuiltinStubEnumTestSkip;

    public function testStringTrimModeBuiltinEnumAndTrim(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('StringTrimMode', false));
echo "\n";
echo trim('  x  ', StringTrimMode::Both), "\n";
echo ltrim('  x  ', StringTrimMode::Left), "\n";
echo rtrim('  x  ', StringTrimMode::Right), "\n";
echo ltrim('  x  ', StringTrimMode::Both), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'string_trim_mode_enum.php'));
        $this->assertSame("true\nx\nx  \n  x\nx\n", ob_get_clean());
    }
}
