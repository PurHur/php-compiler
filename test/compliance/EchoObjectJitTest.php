<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * MCJIT execute echo for object/array/resource (issue #4740).
 *
 * @group llvm
 * @group jit
 */
final class EchoObjectJitTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach (['echo_object_jit.phpt', 'echo_resource_jit.phpt'] as $file) {
            $path = __DIR__.'/cases/language/'.$file;
            $name = preg_replace('/\.phpt$/', '', $file) ?: $file;
            yield $name => self::parsePHPT($path, $file);
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.'
            );
        }
    }
}
