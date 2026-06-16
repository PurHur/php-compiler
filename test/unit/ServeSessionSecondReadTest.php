<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\session\SessionFileStorage;
use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\ext\standard\VmStatCache;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\ScriptExit;
use PHPCompiler\VM\ShutdownQueue;
use PHPCompiler\Web\ProjectBootstrap;
use PHPCompiler\Web\ResponseContext;
use PHPCompiler\Web\ServeCompileCache;
use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/** phpc serve session flash round-trip with per-process compile cache (#1887). */
final class ServeSessionSecondReadTest extends TestCase
{
    private ?string $savedSessionDir = null;

    protected function setUp(): void
    {
        $dir = getenv('PHP_COMPILER_SESSION_DIR');
        $this->savedSessionDir = false !== $dir ? $dir : null;
        VmSession::reset();
        ServeCompileCache::reset();
        ServeCompileCache::enable();
    }

    protected function tearDown(): void
    {
        VmSession::reset();
        ServeCompileCache::reset();
        if (false === $this->savedSessionDir) {
            putenv('PHP_COMPILER_SESSION_DIR');
        } else {
            putenv('PHP_COMPILER_SESSION_DIR='.$this->savedSessionDir);
        }
        parent::tearDown();
    }

    public function testMiniWebAppHomeTwiceWithRequireIncludes(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/examples/003-MiniWebApp/public/index.php';
        $this->assertFileExists($script);

        for ($i = 1; $i <= 2; ++$i) {
            $out = $this->runServeStyleRequest($script, '', '');
            $this->assertStringContainsString('MiniWebApp', $out, 'request '.$i);
        }
    }

    public function testSessionsWebFlashRoundTripWithServeCompileCache(): void
    {
        $dir = sys_get_temp_dir().'/phpc_serve_flash_'.getmypid();
        @mkdir($dir, 0700, true);
        putenv('PHP_COMPILER_SESSION_DIR='.$dir);

        $script = <<<'PHP'
<?php
session_start();
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ('POST' === $method) {
    $_SESSION['flash'] = (string) ($_POST['message'] ?? 'saved');
    session_write_close();
    header('Location: /example.php', true, 303);
    exit;
}
$flash = (string) ($_SESSION['flash'] ?? '');
if ('' !== $flash) {
    unset($_SESSION['flash']);
}
echo $flash;
session_write_close();
PHP;

        $sessionId = '';
        $steps = [
            ['GET', '', ''],
            ['POST', '', 'message=Saved'],
            ['GET', '', ''],
            ['GET', '', ''],
        ];
        $expected = ['', 'Saved', 'Saved', ''];

        foreach ($steps as $i => [$method, $query, $body]) {
            $request = $i + 1;
            putenv('REQUEST_METHOD='.$method);
            if ('' !== $sessionId) {
                putenv('HTTP_COOKIE=PHPSESSID='.$sessionId);
            } else {
                putenv('HTTP_COOKIE');
            }
            if ('POST' === $method) {
                putenv('HTTP_CONTENT_TYPE=application/x-www-form-urlencoded');
            } else {
                putenv('HTTP_CONTENT_TYPE');
            }

            $output = $this->runServeStyleRequest('serve_sess.php', $query, $body, $script);
            if (1 === $request) {
                $files = glob($dir.'/'.SessionFileStorage::PATH_PREFIX.'*');
                $this->assertIsArray($files);
                $this->assertCount(1, $files);
                $sessionId = substr(basename($files[0]), strlen(SessionFileStorage::PATH_PREFIX));
                $this->assertSame('', $output, 'request '.$request);
                continue;
            }
            if (2 === $request) {
                $this->assertSame('', $output, 'POST exits before body');
                continue;
            }

            $this->assertSame($expected[$i], $output, 'request '.$request);
        }
    }

    private function runServeStyleRequest(string $scriptPath, string $queryString, string $body = '', ?string $code = null): string
    {
        if (!is_file($scriptPath)) {
            $this->assertNotNull($code);
            $scriptPath = sys_get_temp_dir().'/phpc_serve_test_'.md5($code).'.php';
            file_put_contents($scriptPath, $code);
        }

        $scriptFilename = realpath($scriptPath);
        if (false === $scriptFilename) {
            $scriptFilename = $scriptPath;
        }
        $documentRoot = dirname($scriptFilename);
        $scriptName = '/'.basename($scriptFilename);
        $requestUri = $scriptName;
        if ('' !== $queryString) {
            $requestUri .= '?'.$queryString;
        }

        putenv('QUERY_STRING='.$queryString);
        putenv('REQUEST_BODY='.$body);
        putenv('SCRIPT_NAME='.$scriptName);
        putenv('SCRIPT_FILENAME='.$scriptFilename);
        putenv('REQUEST_URI='.$requestUri);
        putenv('DOCUMENT_ROOT='.$documentRoot);
        putenv('PATH_INFO');

        ResponseContext::reset();
        ResponseContext::enableHeaderQueue();
        VmSession::reset();
        VmStatCache::reset();
        OutputBuffer::reset();
        ShutdownQueue::reset();

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, $queryString, $body, $scriptFilename);
        [$bootProjectDir, $bootManifest] = ProjectBootstrap::resolveFromScript($scriptFilename);
        ProjectBootstrap::prepare($runtime, $bootProjectDir, $bootManifest);
        $block = ServeCompileCache::getFile($runtime, $scriptFilename);
        $this->assertNotNull($block);
        OutputBuffer::reset();
        ob_start();
        try {
            $runtime->run($block);
            $output = ob_get_clean();
            if (VmSession::isActive()) {
                VmSession::writeClose($runtime->vmContext);
            }
        } catch (ScriptExit $e) {
            ob_end_clean();
            $output = '';
            if (VmSession::isActive()) {
                VmSession::writeClose($runtime->vmContext);
            }
        }

        return $output;
    }
}
