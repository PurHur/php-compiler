<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SPL iterator wrappers excess argc (#30949). */
final class SplIteratorExcessArgc30949VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_spl_iterator_30949.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_spl_iterator_30949.phpt',
            'excess_argc_spl_iterator_30949.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
