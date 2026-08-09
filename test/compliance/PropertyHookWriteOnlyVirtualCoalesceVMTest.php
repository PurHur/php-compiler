<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: virtual set-only write-only Error + ?? (#29240). */
final class PropertyHookWriteOnlyVirtualCoalesceVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'property_hook_writeonly_virtual_coalesce.phpt',
            'property_hook_writeonly_read.phpt',
            'property_hook_writeonly_isset.phpt',
            'property_hook_writeonly_block_read.phpt',
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
