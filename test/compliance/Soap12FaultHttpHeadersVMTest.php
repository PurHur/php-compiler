<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: SoapServer SOAP 1.2/1.1 handle() transport headers (#31957).
 */
final class Soap12FaultHttpHeadersVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'soap_server_soap12_fault_http_headers.phpt',
            'soap_server_soap11_fault_http_headers.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/stdlib/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapServer');
        }
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
