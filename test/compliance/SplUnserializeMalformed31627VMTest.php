<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: SPL unserialize malformed → UnexpectedValueException (#31627).
 */
final class SplUnserializeMalformed31627VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'spl_unserialize_malformed_31627.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/spl_unserialize_malformed_31627.phpt',
            'spl_unserialize_malformed_31627.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
