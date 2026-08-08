<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for #29061 hex-float PROFILE gate (Zend/zend_language_scanner.l).
 *
 * Isolated provider — avoids full VMTest data-provider walk for a four-case lock.
 */
final class HexFloatLiteralProfileGateVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'hex_float_literal.phpt',
            'hex_float_literal_invalid.phpt',
            'hex_float_literal_reject_profile82.phpt',
            'hex_float_literal_reject_default_profile.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
