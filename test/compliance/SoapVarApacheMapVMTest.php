<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: SoapVar APACHE_MAP item/key/value (#32222).
 */
final class SoapVarApacheMapVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'soap_client_soapvar_apache_map.phpt';
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
