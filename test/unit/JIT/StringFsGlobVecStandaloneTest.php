<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringFsGlob;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5459 / #27235: thin AOT links libc JitFsGlobKernel; embed keeps NestedJIT helper.
 *
 * @group aot-lint
 */
final class StringFsGlobVecStandaloneTest extends TestCase
{
    public function testEnsureLinkedCompilesLibcVecForStandalone(): void
    {
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            $this->assertTrue($ctx->isThinStandaloneAotMain());
            StringFsGlob::ensureLinked($ctx);
            $glob = $ctx->module->getNamedFunction('__phpc_glob_vec');
            $scandir = $ctx->module->getNamedFunction('__phpc_scandir_vec');
            $this->assertNotNull($glob);
            $this->assertGreaterThan(0, $glob->countBasicBlocks(), 'JitFsGlobKernel must emit __phpc_glob_vec (#27235)');
            $this->assertNotNull($scandir);
            $this->assertGreaterThan(0, $scandir->countBasicBlocks(), 'JitFsGlobKernel must emit __phpc_scandir_vec (#27236)');
        } finally {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT');
            unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT'], $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT']);
        }
    }
}
