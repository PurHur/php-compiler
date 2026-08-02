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
    public function testEnsureLinkedDefinesStreamIoHelpersForUserScriptAot(): void
    {
        $prev = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
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
                $this->assertFalse(StreamIoRuntime::isDeferStub($fn), $name);
            }
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

    public function testUserScriptLoweringUsesLibcKernelBridges(): void
    {
        $prev = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            // Thin AOT: JitStreamIoKernel libc path (#26929 / #16075).
            StreamIoJit::implement($ctx);
            $tmp = $ctx->module->getNamedFunction('__compiler_tmpfile');
            $this->assertNotNull($tmp);
            $this->assertFalse(StreamIoRuntime::isDeferStub($tmp));
            $this->assertGreaterThan(1, $tmp->countBasicBlocks(), 'libc kernel tmpfile is multi-block');
            $supports = $ctx->module->getNamedFunction('__compiler_stream_supports');
            $this->assertNotNull($supports);
            $this->assertFalse(StreamIoRuntime::isDeferStub($supports));

            StreamIoRuntime::ensureLinkedForUserScriptLowering($ctx);
            $this->assertFalse(StreamIoRuntime::isDeferStub(
                $ctx->module->getNamedFunction('__compiler_tmpfile')
            ));
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
