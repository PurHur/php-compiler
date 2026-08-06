<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** SessionStatus phantom retirement — session_status() stays int (#28203, re-#7321). */
final class SessionStatusEnumTest extends TestCase
{
    protected function tearDown(): void
    {
        VmSession::reset();
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        parent::tearDown();
    }

    public function testSessionStatusPhantomAbsentAndIntHandler(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('SessionStatus', false));
echo "\n";
echo session_status() === PHP_SESSION_NONE ? "none\n" : "bad\n";
echo is_int(session_status()) ? "int\n" : "notint\n";
echo defined('PHP_SESSION_ACTIVE') ? "const_ok\n" : "const_missing\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'session_status_no_enum.php'));
        $this->assertSame("false\nnone\nint\nconst_ok\n", ob_get_clean());
    }
}
