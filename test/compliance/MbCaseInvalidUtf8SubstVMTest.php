<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM compliance for mb_* illegal UTF-8 case/substr substitution (#28629).
 *
 * Dedicated provider so --filter is not tripped by path slashes in VMTest data-set names.
 */
require_once __DIR__.'/../BaseTest.php';

class MbCaseInvalidUtf8SubstVMTest extends BaseTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'mb_case_invalid_utf8_subst.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_case_invalid_utf8_subst.phpt',
            'mb_case_invalid_utf8_subst.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
