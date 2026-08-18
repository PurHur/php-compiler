<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;

require_once __DIR__.'/../BaseTest.php';

/** VM: locale_lookup() RFC 4647 result path (#32118). */
final class LocaleLookupJit32118VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'locale_lookup_jit_32118.phpt';
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
