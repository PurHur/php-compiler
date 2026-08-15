<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: first-class callable in class/enum/file const rejected on PROFILE≤8.4 (#31167).
 *
 * Dedicated provider — full VMTest discovery is heavy, and path-slash data-set
 * names break --filter.
 */
final class ConstFccReject31167VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'const_fcc_reject_84.phpt',
            'const_fcc_file_reject_82.phpt',
            'const_fcc_enum_reject_84.phpt',
            'const_closure_fcc_85.phpt',
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
