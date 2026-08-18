<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: base_convert/addcslashes/date_format/hash_file Zend stub named args (#23507).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class BaseConvertAddcslashesHashFileNamed23507VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'named_args_23507_base_convert_addcslashes_hash_file.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/named_args_23507_base_convert_addcslashes_hash_file.phpt',
            'named_args_23507_base_convert_addcslashes_hash_file.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
