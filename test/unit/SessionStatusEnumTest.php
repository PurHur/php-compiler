<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7321 */
final class SessionStatusEnumTest extends TestCase
{
    protected function tearDown(): void
    {
        VmSession::reset();
        parent::tearDown();
    }

    public function testSessionStatusBuiltinEnumAndHandler(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('SessionStatus', false));
echo "\n";
echo session_status() === PHP_SESSION_NONE ? "none\n" : "bad\n";
echo session_status() === SessionStatus::None ? "enum\n" : "int\n";
session_start();
echo session_status() === PHP_SESSION_ACTIVE ? "active\n" : "bad\n";
session_write_close();
echo session_status() === PHP_SESSION_NONE ? "closed\n" : "bad\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'session_status_enum.php'));
        $this->assertSame("true\nnone\nint\nactive\nclosed\n", ob_get_clean());
    }
}
