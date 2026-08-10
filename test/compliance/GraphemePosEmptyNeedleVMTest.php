<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;

require_once __DIR__.'/../BaseTest.php';

/** VM: grapheme_*pos empty needle → UTF-16 offset (#29495, php-src grapheme_string.c). */
final class GraphemePosEmptyNeedleVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'grapheme_pos_empty_needle.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/grapheme_pos_empty_needle.phpt',
            'grapheme_pos_empty_needle.phpt'
        );
    }

    public function setUp(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('intl extension not advertised — grapheme_* withheld (#17694)');
        }
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
