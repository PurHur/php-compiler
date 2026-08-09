<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for FTP\Connection class finality (ext/ftp/ftp.stub.php; #28403). */
final class FtpConnectionFinalVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'ftp_connection_class_final.phpt',
            'ftp_connection_class_extend_final.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/../compliance/cases/ftp/'.$file,
                $file
            );
        }
    }
}
