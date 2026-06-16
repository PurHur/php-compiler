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
}
