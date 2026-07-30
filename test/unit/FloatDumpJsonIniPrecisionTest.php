<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\VmIni;
use PHPCompiler\ext\standard\VmJsonFormat;
use PHPCompiler\ext\standard\VmPrintRFloat;
use PHPCompiler\ext\standard\VmSerializeFormat;
use PHPCompiler\ext\standard\VmVarExportFloat;
use PHPCompiler\ext\standard\VmZendDoubleString;

/**
 * json_encode / var_export / print_r honor serialize_precision / precision (#25111).
 *
 * php-src: ext/json/json_encoder.c, ext/standard/var.c, Zend/zend_operators.c
 */
final class FloatDumpJsonIniPrecisionTest extends TestCase
{
    protected function tearDown(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        VmIni::restore($ctx, 'serialize_precision');
        VmIni::restore($ctx, 'precision');
    }

    public function testSerializePrecisionShortensJsonAndVarExport(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        VmIni::set($ctx, 'serialize_precision', '10');
        VmIni::set($ctx, 'precision', '6');

        $third = 1 / 3;
        $this->assertSame('0.3333333333', VmSerializeFormat::formatDouble($third));
        $this->assertSame('0.3333333333', VmVarExportFloat::format($third));
        $this->assertSame('0.3333333333', VmJsonFormat::encodeExported($third, 0));
        $this->assertSame('0.333333', VmPrintRFloat::format($third));
        $this->assertSame('0.333333', VmZendDoubleString::format($third));
    }

    public function testPrecisionShortensPrintRNotJson(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        VmIni::set($ctx, 'serialize_precision', '6');
        VmIni::set($ctx, 'precision', '10');

        $third = 1 / 3;
        $this->assertSame('0.333333', VmJsonFormat::encodeExported($third, 0));
        $this->assertSame('0.333333', VmVarExportFloat::format($third));
        $this->assertSame('0.3333333333', VmPrintRFloat::format($third));
    }
}
