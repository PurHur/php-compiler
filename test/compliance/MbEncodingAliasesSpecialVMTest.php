<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for mb_encoding_aliases() libmbfl specials + HTML convert alias (#28983). */
final class MbEncodingAliasesSpecialVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_encoding_aliases_special.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_encoding_aliases_special.phpt',
            'mb_encoding_aliases_special.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
