<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: strcoll/strnatcmp Zend stub names + named args (#23694).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class StrcollStrnatcmpNamed23694VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'strcoll_strnatcmp_named_23694.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/strcoll_strnatcmp_named_23694.phpt',
            'strcoll_strnatcmp_named_23694.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
