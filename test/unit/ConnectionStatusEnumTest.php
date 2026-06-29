<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\BuiltinStubEnumTestSkip;
use PHPUnit\Framework\TestCase;

/** @covers issue #7234 */
final class ConnectionStatusEnumTest extends TestCase
{
    use BuiltinStubEnumTestSkip;

    public function testConnectionStatusBuiltinEnumAndHandler(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('ConnectionStatus', false));
echo "\n";
echo connection_status() === CONNECTION_NORMAL ? "match\n" : "bad\n";
echo connection_status() === ConnectionStatus::Normal ? "enum\n" : "int\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'connection_status_enum.php'));
        $this->assertSame("true\nmatch\nint\n", ob_get_clean());
    }
}
