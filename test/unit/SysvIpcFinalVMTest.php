<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for SysV IPC handle class finality (ext/sysvmsg|sysvsem|sysvshm stubs; #28422). */
final class SysvIpcFinalVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'sysv_ipc_classes_final.phpt',
            'sysv_ipc_classes_extend_final.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/../compliance/cases/sysvmsg/'.$file,
                $file
            );
        }
    }
}
