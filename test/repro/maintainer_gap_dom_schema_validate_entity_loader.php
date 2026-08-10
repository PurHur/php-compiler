<?php

declare(strict_types=1);

/**
 * #29596 — schemaValidate/relaxNGValidate must invoke libxml_set_external_entity_loader.
 *
 * php-src: ext/dom/document.c + ext/libxml/libxml.c (php_libxml_external_entity_loader)
 */
$tmp = sys_get_temp_dir().'/phpc_schema_loader_'.getmypid();
@mkdir($tmp);
$xsd = $tmp.'/ok.xsd';
$rng = $tmp.'/ok.rng';
$missingXsd = $tmp.'/missing.xsd';
$missingRng = $tmp.'/missing.rng';
$badDtd = $tmp.'/bad.dtd';

$xsdBody = <<<'XSD'
<?xml version="1.0"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="root" type="xs:string"/>
</xs:schema>
XSD;
$rngBody = <<<'RNG'
<?xml version="1.0"?>
<element name="root" xmlns="http://relaxng.org/ns/structure/1.0">
  <text/>
</element>
RNG;
file_put_contents($xsd, $xsdBody);
file_put_contents($rng, $rngBody);
file_put_contents($badDtd, "<!ELEMENT root (#PCDATA)>\n");
@unlink($missingXsd);
@unlink($missingRng);

$doc = new DOMDocument();
$doc->loadXML('<root>hi</root>');

function run_case(string $label, callable $fn): void
{
    libxml_use_internal_errors(true);
    libxml_clear_errors();
    $calls = 0;
    $seen = [];
    echo "== $label ==\n";
    try {
        $fn($calls, $seen);
    } catch (Throwable $e) {
        echo 'exception='.get_class($e).':'.$e->getMessage()."\n";
    }
    $errs = libxml_get_errors();
    echo 'calls='.$calls."\n";
    echo 'seen='.implode('|', $seen)."\n";
    echo 'err_count='.count($errs)."\n";
    foreach ($errs as $e) {
        echo 'err='.trim($e->message).' code='.$e->code.' level='.$e->level."\n";
    }
    libxml_set_external_entity_loader(null);
    echo "\n";
}

run_case('xsd_existing_loader_returns_dtd', function (&$calls, &$seen) use ($doc, $xsd, $badDtd) {
    libxml_set_external_entity_loader(function ($pub, $sys, $ctx) use (&$calls, &$seen, $badDtd) {
        $calls++;
        $seen[] = (string) $sys;

        return $badDtd;
    });
    $ok = @$doc->schemaValidate($xsd);
    echo 'ok='.var_export($ok, true)."\n";
});

run_case('xsd_missing_loader_returns_valid', function (&$calls, &$seen) use ($doc, $missingXsd, $xsd) {
    libxml_set_external_entity_loader(function ($pub, $sys, $ctx) use (&$calls, &$seen, $xsd) {
        $calls++;
        $seen[] = (string) $sys;

        return $xsd;
    });
    $ok = @$doc->schemaValidate($missingXsd);
    echo 'ok='.var_export($ok, true)."\n";
});

run_case('xsd_existing_loader_returns_null', function (&$calls, &$seen) use ($doc, $xsd) {
    libxml_set_external_entity_loader(function ($pub, $sys, $ctx) use (&$calls, &$seen) {
        $calls++;
        $seen[] = (string) $sys;

        return null;
    });
    $ok = @$doc->schemaValidate($xsd);
    echo 'ok='.var_export($ok, true)."\n";
});

run_case('rng_existing_loader_returns_dtd', function (&$calls, &$seen) use ($doc, $rng, $badDtd) {
    libxml_set_external_entity_loader(function ($pub, $sys, $ctx) use (&$calls, &$seen, $badDtd) {
        $calls++;
        $seen[] = (string) $sys;

        return $badDtd;
    });
    $ok = @$doc->relaxNGValidate($rng);
    echo 'ok='.var_export($ok, true)."\n";
});

run_case('rng_missing_loader_returns_valid', function (&$calls, &$seen) use ($doc, $missingRng, $rng) {
    libxml_set_external_entity_loader(function ($pub, $sys, $ctx) use (&$calls, &$seen, $rng) {
        $calls++;
        $seen[] = (string) $sys;

        return $rng;
    });
    $ok = @$doc->relaxNGValidate($missingRng);
    echo 'ok='.var_export($ok, true)."\n";
});

run_case('rng_existing_loader_returns_null', function (&$calls, &$seen) use ($doc, $rng) {
    libxml_set_external_entity_loader(function ($pub, $sys, $ctx) use (&$calls, &$seen) {
        $calls++;
        $seen[] = (string) $sys;

        return null;
    });
    $ok = @$doc->relaxNGValidate($rng);
    echo 'ok='.var_export($ok, true)."\n";
});

run_case('xsd_no_loader_existing', function (&$calls, &$seen) use ($doc, $xsd) {
    $ok = @$doc->schemaValidate($xsd);
    echo 'ok='.var_export($ok, true)."\n";
});

@unlink($xsd);
@unlink($rng);
@unlink($badDtd);
@rmdir($tmp);
