<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** MemoryUsage phantom retirement — memory_get_* stay bool (#28411, re-#7247). */
final class MemoryUsageEnumTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        parent::tearDown();
    }

    public function testMemoryUsagePhantomAbsentAndBoolHandlers(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('MemoryUsage', false));
echo "\n";
echo memory_get_usage(false) > 0 ? "default\n" : "bad\n";
echo memory_get_usage(true) > 0 ? "real\n" : "bad\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'memory_usage_no_enum.php'));
        $this->assertSame("false\ndefault\nreal\n", ob_get_clean());
    }
}
