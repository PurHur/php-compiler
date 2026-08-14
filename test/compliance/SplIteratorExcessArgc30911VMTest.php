<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ArrayIterator/SplStack excess argc → ArgumentCountError (#30911). */
final class SplIteratorExcessArgc30911VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_spl_iterator_30911.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_spl_iterator_30911.phpt',
            'excess_argc_spl_iterator_30911.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
