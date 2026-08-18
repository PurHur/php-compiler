<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: base_convert/addcslashes/hash_file named Zend stub args (#23507).
 */
final class BaseConvertAddcslashesHashFileNamed23507JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'named_args_23507_base_convert_addcslashes_hash_file_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/named_args_23507_base_convert_addcslashes_hash_file_jit.phpt',
            'named_args_23507_base_convert_addcslashes_hash_file_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
