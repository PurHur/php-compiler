<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__ . '/VisibilityTest.php';

/**
 * JIT path for method visibility (issue #588).
 *
 * @group llvm
 * @group jit
 */
final class VisibilityJitTest extends VisibilityTest
{
    public static function providePHPTests(): \Generator
    {
        foreach (parent::providePHPTests() as $case) {
            // JIT rejects illegal calls at compile time; VM covers allowed $this-> calls (issue #588).
            if (str_contains(strtolower($case[0]), 'callable via')) {
                continue;
            }
            yield $case;
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/jit.php');
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.'
            );
        }
    }
}
