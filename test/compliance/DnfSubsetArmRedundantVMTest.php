<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: DNF arm proper-subset is more restrictive (#26607).
 */
final class DnfSubsetArmRedundantVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'dnf_subset_arm_redundant.phpt',
            'dnf_subset_arm_redundant_reverse.phpt',
            'dnf_subset_single_vs_intersection.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
