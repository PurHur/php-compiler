<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: parse_ini_file() runtime path + empty filename (#30756).
 *
 * @group llvm
 * @group jit
 */
final class ParseIniFileJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'parse_ini_file_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/parse_ini_file_jit.phpt',
            'parse_ini_file_jit.phpt'
        );
        yield 'parse_ini_file_empty_path_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/parse_ini_file_empty_path_jit.phpt',
            'parse_ini_file_empty_path_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
