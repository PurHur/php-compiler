<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * User-script AOT LLVM compile for DOMNode::appendChild() (#18478, ext/dom/node.c).
 *
 * @group llvm
 * @group aot-lint
 */
final class DomAppendChildUserScriptAotTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — DOM appendChild user-script AOT compile needs LLVM');
        }
    }

    public function testDocumentElementAppendChildCompilesUnderUserScriptAot(): void
    {
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        try {
            $code = <<<'PHP'
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<root/>');
$doc->documentElement->appendChild($doc->createElement('a'));
PHP;
            $runtime = new Runtime(Runtime::MODE_AOT);
            $block = $runtime->parseAndCompile($code, 'dom_append_child_user_script_aot.php');
            $this->assertNotNull($block);
            $runtime->jitCompileBlock($block);
            $ctx = $runtime->loadJitContext();
            $this->assertTrue($ctx->functionIsRegistered('domnode::appendchild'));
        } finally {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT');
        }
    }
}
