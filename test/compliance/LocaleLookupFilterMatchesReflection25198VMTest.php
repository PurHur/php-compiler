<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for locale_lookup()/locale_filter_matches() Reflection stubs (#25198). */
final class LocaleLookupFilterMatchesReflection25198VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'locale_lookup_filter_matches_reflection_25198.phpt';
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
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
