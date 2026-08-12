<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: pfsockopen(null) soft Deprecated+Warning+false (#30393). */
final class PfsockopenNullSoftVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'pfsockopen_null_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/pfsockopen_null_soft.phpt',
            'pfsockopen_null_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
