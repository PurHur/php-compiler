<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for user __destruct() JIT/AOT lowering (#4013, #4096).
 *
 * @group llvm
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_objects.c zend_objects_destroy_object
 */
final class UserDestructJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — user __destruct JIT compile test needs LLVM');
        }
    }

    public function testUserDestructModuleVerifies(): void
    {
        $code = <<<'PHP'
<?php
class R {
    public function __destruct() {
        echo "dtor\n";
    }
}
$o = new R();
unset($o);
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'user_destruct_jit_compile.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);

        $module = $context->module;
        $this->assertNotNull($module->getNamedFunction('__object__invoke_destructor'));
        $this->assertNotNull($module->getNamedFunction('phpc_destruct_try_invoke'));
        $this->assertNotNull($module->getNamedFunction('phpc_gc_run_shutdown_destructors'));
    }
}
