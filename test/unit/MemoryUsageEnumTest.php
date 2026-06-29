<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\BuiltinStubEnumTestSkip;
use PHPUnit\Framework\TestCase;

/** @covers issue #7247 */
final class MemoryUsageEnumTest extends TestCase
{
    use BuiltinStubEnumTestSkip;

    public function testMemoryUsageBuiltinEnumAndHandlers(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('MemoryUsage', false));
echo "\n";
echo memory_get_usage(MemoryUsage::Default) > 0 ? "default\n" : "bad\n";
echo memory_get_usage(MemoryUsage::RealUsage) > 0 ? "real\n" : "bad\n";
echo memory_get_usage(true) > 0 ? "legacy\n" : "bad\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'memory_usage_enum.php'));
        $this->assertSame("true\ndefault\nreal\nlegacy\n", ob_get_clean());
    }
}
