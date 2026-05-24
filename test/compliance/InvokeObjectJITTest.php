<?php

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/**
 * JIT compliance for invokable objects / __invoke (issue #1232).
 *
 * @group llvm
 * @group jit
 */
class InvokeObjectJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/jit.php');
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $reason = LlvmToolchain::readyFailureReason();
            $detail = null !== $reason && '' !== $reason
                ? $reason
                : 'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.';
            $this->markTestSkipped($detail);
        }
    }

    public static function providePHPTests(): \Generator
    {
        foreach (['invoke_object_jit.phpt'] as $file) {
            $path = __DIR__ . '/cases/language/' . $file;
            $name = preg_replace('/\.phpt$/', '', $file) ?: $file;
            yield $name => self::parsePHPT($path, $file);
        }
    }
}
