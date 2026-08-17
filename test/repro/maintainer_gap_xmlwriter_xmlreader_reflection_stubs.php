<?php

declare(strict_types=1);

// Reflection residual (#31867): XMLWriter *Ns/DTD/startDocument/flush + XMLReader next/setSchema.

function dumpParam(string $cls, string $method, int $i): void
{
    $p = (new ReflectionMethod($cls, $method))->getParameters()[$i];
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    $def = 'n/a';
    try {
        if ($p->isDefaultValueAvailable()) {
            $def = var_export($p->getDefaultValue(), true);
        }
    } catch (Throwable $e) {
        $def = 'unavailable';
    }
    echo $cls, '::', $method, ' arg', $i, '=', $type,
        ' opt=', ($p->isOptional() ? '1' : '0'),
        ' def=', $def, "\n";
}

function dumpFn(string $fn, int $i): void
{
    $p = (new ReflectionFunction($fn))->getParameters()[$i];
    $type = $p->hasType() ? (string) $p->getType() : 'none';
    $def = 'n/a';
    try {
        if ($p->isDefaultValueAvailable()) {
            $def = var_export($p->getDefaultValue(), true);
        }
    } catch (Throwable $e) {
        $def = 'unavailable';
    }
    echo $fn, ' arg', $i, '=', $type,
        ' opt=', ($p->isOptional() ? '1' : '0'),
        ' def=', $def, "\n";
}

dumpParam('XMLWriter', 'startAttributeNs', 0);
dumpParam('XMLWriter', 'startAttributeNs', 2);
dumpParam('XMLWriter', 'startElementNs', 0);
dumpParam('XMLWriter', 'startElementNs', 2);
dumpParam('XMLWriter', 'writeAttributeNs', 0);
dumpParam('XMLWriter', 'writeAttributeNs', 2);
dumpParam('XMLWriter', 'writeElementNs', 0);
dumpParam('XMLWriter', 'writeElementNs', 2);
dumpParam('XMLWriter', 'writeElementNs', 3);
dumpParam('XMLWriter', 'startDocument', 0);
dumpParam('XMLWriter', 'startDtd', 0);
dumpParam('XMLWriter', 'startDtd', 1);
echo 'startDtd_names=', (new ReflectionMethod('XMLWriter', 'startDtd'))->getParameters()[0]->getName(), ',',
    (new ReflectionMethod('XMLWriter', 'startDtd'))->getParameters()[1]->getName(), "\n";
dumpParam('XMLWriter', 'writeDtd', 1);
dumpParam('XMLWriter', 'writeDtd', 3);
echo 'writeDtdEntity_isParam=', (new ReflectionMethod('XMLWriter', 'writeDtdEntity'))->getParameters()[2]->getName(), "\n";
dumpParam('XMLWriter', 'writeDtdEntity', 2);
dumpParam('XMLWriter', 'writeDtdEntity', 3);
dumpParam('XMLWriter', 'flush', 0);
dumpParam('XMLWriter', 'outputMemory', 0);
echo 'writeDtdEntity_req=', (new ReflectionMethod('XMLWriter', 'writeDtdEntity'))->getNumberOfRequiredParameters(), "\n";
dumpParam('XMLReader', 'next', 0);
dumpParam('XMLReader', 'setSchema', 0);
dumpParam('XMLReader', 'setRelaxNGSchema', 0);
dumpParam('XMLReader', 'setRelaxNGSchemaSource', 0);
dumpFn('xmlwriter_flush', 0);
dumpFn('xmlwriter_flush', 1);
dumpFn('xmlwriter_start_document', 1);
dumpFn('xmlwriter_start_attribute_ns', 1);
dumpFn('xmlwriter_write_element_ns', 4);
$rf = new ReflectionFunction('xml_error_string');
echo 'xml_error_string_ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none', "\n";
$opt = new ReflectionFunction('xml_parser_set_option');
echo 'xml_parser_set_option_ret=', $opt->hasReturnType() ? (string) $opt->getReturnType() : 'none', "\n";
dumpFn('xml_parser_set_option', 0);
dumpFn('xml_get_error_code', 0);

$w = new XMLWriter();
$w->openMemory();
$w->startDocument();
echo 'runtime_startDocument=', $w->outputMemory(false) !== '' ? '1' : '0', "\n";
$w2 = new XMLWriter();
$w2->openMemory();
$w2->startElement('root');
echo 'runtime_ns_null=', $w2->startAttributeNs(null, 'x', null) ? '1' : '0', "\n";
