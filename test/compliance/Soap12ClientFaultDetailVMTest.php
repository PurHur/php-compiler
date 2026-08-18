<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: SoapClient SOAP 1.2 Fault Detail → detail (#32047).
 */
final class Soap12ClientFaultDetailVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'soap_client_soap12_fault_detail.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/stdlib/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapClient');
        }
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
