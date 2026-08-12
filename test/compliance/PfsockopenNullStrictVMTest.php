<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: pfsockopen(null) TypeError under strict_types (#30393). */
final class PfsockopenNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'pfsockopen_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/pfsockopen_null_strict.phpt',
            'pfsockopen_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
