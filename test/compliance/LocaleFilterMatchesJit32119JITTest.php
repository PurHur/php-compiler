<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;

require_once __DIR__.'/../BaseTest.php';

/** JIT: locale_filter_matches() prefix-filter result path (#32119). */
final class LocaleFilterMatchesJit32119JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'locale_filter_matches_jit_32119.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/intl/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        if (!IntlExtensionPolicy::advertisesLocale()) {
            $this->markTestSkipped('intl extension not advertised — locale_* withheld (#19670)');
        }
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
