<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: typed-property / unset-static Error getFile()/getLine() user site (#31859).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class TypedPropErrorGetfile31859VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'typed_prop_error_getfile_31859.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_prop_error_getfile_31859.phpt',
            'typed_prop_error_getfile_31859.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
