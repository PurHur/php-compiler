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
echo session_status() === SessionStatus::None ? "none\n" : "bad\n";
echo session_status()->value === PHP_SESSION_NONE ? "legacy\n" : "bad\n";
session_start();
echo session_status() === SessionStatus::Active ? "active\n" : "bad\n";
session_write_close();
echo session_status() === SessionStatus::None ? "closed\n" : "bad\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'session_status_enum.php'));
        $this->assertSame("true\nnone\nlegacy\nactive\nclosed\n", ob_get_clean());
    }
}
