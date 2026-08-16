<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: utf8_encode/decode E_DEPRECATED profile wording (#31176, re-#29249).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class Utf8EndecDeprecated31176VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'utf8_endec_deprecated.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/utf8_endec_deprecated.phpt',
            'utf8_endec_deprecated.phpt'
        );
        yield 'utf8_endec_deprecated_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/utf8_endec_deprecated_forward84.phpt',
            'utf8_endec_deprecated_forward84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
