--TEST--
DOMDocument::schemaValidate()/relaxNGValidate() honor libxml_set_external_entity_loader (#29596, ext/dom + ext/libxml)
--FILE--
<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir().'/phpc_dom_schema_ent_'.getmypid();
@mkdir($tmp);
$xsd = $tmp.'/ok.xsd';
$rng = $tmp.'/ok.rng';
$missingXsd = $tmp.'/missing.xsd';
$missingRng = $tmp.'/missing.rng';
$badDtd = $tmp.'/bad.dtd';
file_put_contents($xsd, '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="root" type="xs:string"/></xs:schema>');
file_put_contents($rng, '<?xml version="1.0"?><element name="root" xmlns="http://relaxng.org/ns/structure/1.0"><text/></element>');
file_put_contents($badDtd, "<!ELEMENT root (#PCDATA)>\n");
@unlink($missingXsd);
@unlink($missingRng);

$doc = new DOMDocument();
$doc->loadXML('<root>hi</root>');

libxml_use_internal_errors(true);

$calls = 0;
libxml_clear_errors();
libxml_set_external_entity_loader(function ($public, $system, $context) use (&$calls, $badDtd) {
    $calls++;
    return $badDtd;
});
$xsdDtdOk = $doc->schemaValidate($xsd);
$xsdDtdErrs = libxml_get_errors();
echo 'xsd_dtd_calls=', $calls, ' ok=', ($xsdDtdOk ? '1' : '0'), ' errs=', count($xsdDtdErrs);
if (count($xsdDtdErrs) >= 1) {
    echo ' last=', trim($xsdDtdErrs[count($xsdDtdErrs) - 1]->message), ' code=', $xsdDtdErrs[count($xsdDtdErrs) - 1]->code;
}
echo "\n";

$calls = 0;
libxml_clear_errors();
libxml_set_external_entity_loader(function ($public, $system, $context) use (&$calls, $xsd) {
    $calls++;
    return $xsd;
});
$xsdMissingOk = $doc->schemaValidate($missingXsd);
echo 'xsd_missing_ok_calls=', $calls, ' ok=', ($xsdMissingOk ? '1' : '0'), "\n";

$calls = 0;
libxml_clear_errors();
libxml_set_external_entity_loader(function ($public, $system, $context) use (&$calls) {
    $calls++;
    return null;
});
$xsdNullOk = $doc->schemaValidate($xsd);
$xsdNullErrs = libxml_get_errors();
echo 'xsd_null_calls=', $calls, ' ok=', ($xsdNullOk ? '1' : '0'), ' errs=', count($xsdNullErrs);
if (isset($xsdNullErrs[0], $xsdNullErrs[1])) {
    echo ' e0=', trim($xsdNullErrs[0]->message), ' code=', $xsdNullErrs[0]->code;
    echo ' e1=', trim($xsdNullErrs[1]->message), ' code=', $xsdNullErrs[1]->code;
}
echo "\n";

$calls = 0;
libxml_clear_errors();
libxml_set_external_entity_loader(function ($public, $system, $context) use (&$calls, $badDtd) {
    $calls++;
    return $badDtd;
});
$rngDtdOk = $doc->relaxNGValidate($rng);
$rngDtdErrs = libxml_get_errors();
echo 'rng_dtd_calls=', $calls, ' ok=', ($rngDtdOk ? '1' : '0'), ' errs=', count($rngDtdErrs);
if (count($rngDtdErrs) >= 1) {
    echo ' last=', trim($rngDtdErrs[count($rngDtdErrs) - 1]->message), ' code=', $rngDtdErrs[count($rngDtdErrs) - 1]->code;
}
echo "\n";

$calls = 0;
libxml_clear_errors();
libxml_set_external_entity_loader(function ($public, $system, $context) use (&$calls, $rng) {
    $calls++;
    return $rng;
});
$rngMissingOk = $doc->relaxNGValidate($missingRng);
echo 'rng_missing_ok_calls=', $calls, ' ok=', ($rngMissingOk ? '1' : '0'), "\n";

$calls = 0;
libxml_clear_errors();
libxml_set_external_entity_loader(function ($public, $system, $context) use (&$calls) {
    $calls++;
    return null;
});
$rngNullOk = $doc->relaxNGValidate($rng);
$rngNullErrs = libxml_get_errors();
echo 'rng_null_calls=', $calls, ' ok=', ($rngNullOk ? '1' : '0'), ' errs=', count($rngNullErrs);
if (isset($rngNullErrs[0], $rngNullErrs[1])) {
    echo ' e0=', trim($rngNullErrs[0]->message), ' code=', $rngNullErrs[0]->code;
    echo ' e1=', trim($rngNullErrs[1]->message), ' code=', $rngNullErrs[1]->code;
}
echo "\n";

libxml_set_external_entity_loader(null);
@unlink($xsd);
@unlink($rng);
@unlink($badDtd);
@rmdir($tmp);
?>
--EXPECTF--
xsd_dtd_calls=1 ok=0 errs=%d last=Failed to parse the XML resource '%s/ok.xsd'. code=3067
xsd_missing_ok_calls=1 ok=1
xsd_null_calls=1 ok=0 errs=2 e0=Failed to load external entity because the resolver function returned null code=1 e1=Failed to parse the XML resource '%s/ok.xsd'. code=3067
rng_dtd_calls=1 ok=0 errs=%d last=xmlRelaxNGParse: could not load %s/ok.rng code=1065
rng_missing_ok_calls=1 ok=1
rng_null_calls=1 ok=0 errs=2 e0=Failed to load external entity because the resolver function returned null code=1 e1=xmlRelaxNGParse: could not load %s/ok.rng code=1065
