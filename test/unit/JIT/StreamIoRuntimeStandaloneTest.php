<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamIo;
use PHPCompiler\JIT\Builtin\StreamIoJit;
use PHPCompiler\JIT\Builtin\StreamIoRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * fopen/fread/fwrite/tmpfile LLVM helpers must lower without C symbols in phpc_stream.c (#5343 phase 3).
 *
 * @group aot-lint
 */
final class StreamIoRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStreamIoHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamIo::ensureLinked($ctx);

        foreach ([
            '__compiler_fwrite',
            '__compiler_fopen',
            '__compiler_tmpfile',
            '__compiler_fread',
            '__compiler_stream_supports',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }

    public function testUserScriptLoweringUpgradesDeferStubs(): void
    {
        $prev = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            StreamIoJit::implement($ctx);
            $tmpBefore = $ctx->module->getNamedFunction('__compiler_tmpfile');
            $this->assertNotNull($tmpBefore);
            $this->assertTrue(StreamIoRuntime::isDeferStub($tmpBefore));

            StreamIoRuntime::ensureLinkedForUserScriptLowering($ctx);

            $tmpAfter = $ctx->module->getNamedFunction('__compiler_tmpfile');
            $this->assertNotNull($tmpAfter);
            $this->assertFalse(StreamIoRuntime::isDeferStub($tmpAfter));
            $supports = $ctx->module->getNamedFunction('__compiler_stream_supports');
            $this->assertNotNull($supports);
            $this->assertFalse(StreamIoRuntime::isDeferStub($supports));
        } finally {
            if (false === $prev || '' === (string) $prev) {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
                unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT'], $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT']);
            } else {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT='.$prev);
                $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = $prev;
                $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = $prev;
            }
        }
    }
}
