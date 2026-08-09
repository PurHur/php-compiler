<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for Shmop class finality (ext/shmop/shmop.stub.php; #28423). */
final class ShmopFinalVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'shmop_class_final.phpt',
            'shmop_class_extend_final.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/../compliance/cases/sysvshm/'.$file,
                $file
            );
        }
    }
}
