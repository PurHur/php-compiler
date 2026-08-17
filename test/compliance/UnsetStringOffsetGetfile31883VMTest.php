<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: unset($string[$k]) Error getFile()/getLine() user site (#31883).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class UnsetStringOffsetGetfile31883VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'unset_string_offset_getfile_31883.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/unset_string_offset_getfile_31883.phpt',
            'unset_string_offset_getfile_31883.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
