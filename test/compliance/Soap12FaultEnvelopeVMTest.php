<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: SoapServer::fault SOAP 1.2 env:Code/Reason envelope (#31944).
 *
 * Dedicated provider — full VMTest discovery is heavy, and path-slash data-set
 * names break --filter.
 */
final class Soap12FaultEnvelopeVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'soap_server_soap12_fault.phpt',
            'soap_server_soap12_fault_envelope.phpt',
            'soap_server_fault_headerfault.phpt',
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
