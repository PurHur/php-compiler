<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Session builtins: two-request $_SESSION persistence (#64, #1182–#1186).
 */
final class SessionBuiltinTest extends TestCase
{
    private ?string $sessionDir = null;

    protected function tearDown(): void
    {
        if (null !== $this->sessionDir && is_dir($this->sessionDir)) {
            foreach (glob($this->sessionDir.'/sess_*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->sessionDir);
        }
        putenv('PHP_COMPILER_SESSION_DIR');
        parent::tearDown();
    }

    public function testSessionPersistsAcrossVmRequests(): void
    {
        $this->sessionDir = sys_get_temp_dir().'/phpc_session_test_'.uniqid('', true);
        putenv('PHP_COMPILER_SESSION_DIR='.$this->sessionDir);

        $writeCode = <<<'PHP'
session_start();
$_SESSION['user'] = 'dev';
$id = session_id();
session_write_close();
echo $id;
PHP;

        $sessionId = $this->runVm($writeCode);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $sessionId);

        putenv('HTTP_COOKIE=PHPSESSID='.$sessionId);

        $readCode = <<<'PHP'
session_start();
echo $_SESSION['user'] ?? 'missing';
PHP;

        $this->assertSame('dev', $this->runVm($readCode));

        putenv('HTTP_COOKIE');
    }

    public function testSessionDestroyClearsData(): void
    {
        $this->sessionDir = sys_get_temp_dir().'/phpc_session_test_'.uniqid('', true);
        putenv('PHP_COMPILER_SESSION_DIR='.$this->sessionDir);

        $writeCode = <<<'PHP'
session_start();
$_SESSION['token'] = 'abc';
$id = session_id();
session_write_close();
echo $id;
PHP;

        $sessionId = $this->runVm($writeCode);
        putenv('HTTP_COOKIE=PHPSESSID='.$sessionId);

        $destroyCode = <<<'PHP'
session_start();
session_destroy();
session_start();
echo isset($_SESSION['token']) ? 'present' : 'gone';
PHP;

        $this->assertSame('gone', $this->runVm($destroyCode));

        putenv('HTTP_COOKIE');
    }

    public function testSessionRegenerateIdRotatesCookie(): void
    {
        $this->sessionDir = sys_get_temp_dir().'/phpc_session_test_'.uniqid('', true);
        putenv('PHP_COMPILER_SESSION_DIR='.$this->sessionDir);

        $code = <<<'PHP'
session_start();
$old = session_id();
session_regenerate_id(true);
$new = session_id();
echo ($old !== $new && strlen($new) === 32) ? 'rotated' : 'fail';
PHP;

        $this->assertSame('rotated', $this->runVm($code));
    }

    private function runVm(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_session_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $proc = proc_open(
            ['php', $repo.'/bin/vm.php', $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            null
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, trim((string) $err));

        return preg_replace('/\r\n?/', "\n", trim((string) $out)) ?? '';
    }
}
