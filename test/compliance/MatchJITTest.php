<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for match expression subset (#2398, #2428). */
final class MatchJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach (
            [
                'match_int_jit.phpt',
                'match_identical_jit.phpt',
                'match_guard_falsy_jit.phpt',
                'match_unhandled_jit.phpt',
                'match_enum_unhandled_jit.phpt',
                'match_scalar_enum_arm_jit.phpt',
                'match_enum_subject_scalar_arm_jit.phpt',
                'match_switch_enum_strict_jit.phpt',
                'match_enum_default_jit.phpt',
                'match_object_unhandled_jit.phpt',
                'match_duplicate_default_jit.phpt',
                'match_default_not_last_jit.phpt',
            ] as $file
        ) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
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
