<?php

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Backend\VM\Runtime;

require_once __DIR__ . '/../BaseTest.php';

/**
 * @group llvm
 * @group jit
 */
class JITTest extends BaseTest {

    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach (parent::providePHPTests() as $case) {
            // ?-> on objects needs JIT class/property support (#308); VM compliance covers it.
            if (str_contains(strtolower($case[0]), 'nullsafe')) {
                continue;
            }
            // password_hash()/password_verify() are VM-only (#172).
            if (str_contains(strtolower($case[0]), 'password')) {
                continue;
            }
            // func_get_args()/func_num_args() remain VM-only (#197).
            if (str_contains(strtolower($case[0]), 'func_get_args')) {
                continue;
            }
            yield $case;
        }
    }

    public function setUp(): void {
        $this->BIN = realpath(__DIR__ . '/../../bin/jit.php');
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $reason = LlvmToolchain::readyFailureReason();
            $detail = null !== $reason && '' !== $reason
                ? $reason
                : 'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.';
            $this->markTestSkipped($detail);
        }
    }

}