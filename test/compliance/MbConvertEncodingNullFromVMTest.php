<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: mb_convert_encoding(null $from_encoding) uses internal encoding (#31488).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class MbConvertEncodingNullFromVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_convert_encoding_null_from.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_convert_encoding_null_from.phpt',
            'mb_convert_encoding_null_from.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
