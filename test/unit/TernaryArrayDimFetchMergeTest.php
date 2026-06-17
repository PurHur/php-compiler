<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\Superglobals;

/**
 * ?: true arm must not reuse isset() dim-key slots (issue #1887, 005-SessionsWeb).
 */
final class TernaryArrayDimFetchMergeTest extends TestCase
{
    private function runWithPostBody(string $source): string
    {
        putenv('REQUEST_METHOD=POST');
        putenv('REQUEST_BODY=message=Saved');
        putenv('HTTP_CONTENT_TYPE=application/x-www-form-urlencoded');
        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', 'message=Saved');
        $block = $runtime->parseAndCompile($source, 'ternary_post.php');
        VM\OutputBuffer::reset();
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
            // no exit in these snippets
        }

        return (string) ob_get_clean();
    }

    public function testIssetSuperglobalTernaryReturnsValueNotKey(): void
    {
        $out = $this->runWithPostBody(<<<'PHP'
<?php
declare(strict_types=1);
$v = isset($_POST['message']) ? (string) $_POST['message'] : 'saved';
echo $v, "\n";
PHP);
        $this->assertSame("Saved\n", $out);
    }

    public function testLiteralConditionTernaryArrayDimFetchReturnsValue(): void
    {
        $out = $this->runWithPostBody(<<<'PHP'
<?php
declare(strict_types=1);
$v = true ? (string) $_POST['message'] : 'saved';
echo $v, "\n";
PHP);
        $this->assertSame("Saved\n", $out);
    }

    /** 005 example POST must persist flash in session file (#9226). */
    public function test005SessionsWebExamplePostPersistsFlash(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/examples/005-SessionsWeb/example.php';
        $this->assertFileExists($script);

        $dir = sys_get_temp_dir().'/phpc_005_flash_'.getmypid();
        @mkdir($dir, 0700, true);
        putenv('PHP_COMPILER_SESSION_DIR='.$dir);
        putenv('REQUEST_METHOD=POST');
        putenv('REQUEST_BODY=message=Saved');
        putenv('HTTP_CONTENT_TYPE=application/x-www-form-urlencoded');

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', 'message=Saved');
        $block = $runtime->parseAndCompile((string) file_get_contents($script), 'example.php');
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
        }
        ob_end_clean();

        $files = glob($dir.'/sess_*') ?: [];
        $this->assertCount(1, $files);
        $this->assertStringContainsString('Saved', (string) file_get_contents($files[0]));
    }
}
