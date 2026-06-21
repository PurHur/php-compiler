<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ScriptExit;
use PHPCompiler\Web\ResponseContext;
use PHPCompiler\Web\Superglobals;
use PHPCompiler\ext\standard\VmSession;

/** header() literal args after stmt-level ?? must not reuse coalesce slots (#1887, 005-SessionsWeb). */
final class HeaderAfterSessionWriteCloseTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('REQUEST_METHOD=POST');
        putenv('REQUEST_BODY=message=Saved');
        putenv('HTTP_CONTENT_TYPE=application/x-www-form-urlencoded');
        putenv('GATEWAY_INTERFACE=CGI/1.1');
    }

    protected function tearDown(): void
    {
        putenv('REQUEST_METHOD');
        putenv('REQUEST_BODY');
        putenv('HTTP_CONTENT_TYPE');
        putenv('GATEWAY_INTERFACE');
    }

    public function testHeader303AfterPostCoalesceAndSessionWriteCloseInPostBranch(): void
    {
        $source = <<<'PHP'
<?php
declare(strict_types=1);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ('POST' === $method) {
    session_start();
    $_SESSION['flash'] = (string) ($_POST['message'] ?? 'saved');
    session_write_close();
    header('Location: /example.php', true, 303);
    exit;
}
PHP;

        $status = $this->runPostBranch($source);
        $this->assertSame(303, $status);
    }

    public function test005SessionsWebExamplePostBranchReturns303(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/examples/005-SessionsWeb/example.php';
        $this->assertFileExists($script);

        $status = $this->runPostBranch((string) file_get_contents($script));
        $this->assertSame(303, $status);
    }

    private function runPostBranch(string $source): int
    {
        ResponseContext::reset();
        ResponseContext::enableHeaderQueue();
        VmSession::reset();

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', 'message=Saved');
        $block = $runtime->parseAndCompile($source, 'sessions_post.php');
        $this->assertNotNull($block);

        ob_start();
        try {
            $runtime->run($block);
        } catch (ScriptExit) {
            ob_end_clean();
        }

        return ResponseContext::getStatus();
    }
}
