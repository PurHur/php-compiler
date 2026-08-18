<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: SoapClient SoapVar XSD_ANYXML raw XML embed (#32241).
 */
final class SoapVarXsdAnyxmlVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'soap_client_soapvar_xsd_anyxml.phpt';
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
