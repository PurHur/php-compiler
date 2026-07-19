<?php
declare(strict_types=1);

/** #20776 — schemaValidate/relaxNGValidate missing path under libxml_use_internal_errors. */

libxml_use_internal_errors(true);
libxml_clear_errors();

$doc = new DOMDocument();
$doc->loadXML('<r/>');

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});

$schemaOk = $doc->schemaValidate('/tmp/definitely-missing-schema-xyz.xsd');
$schemaWarnings = $warnings;
$schemaErrors = libxml_get_errors();

$warnings = [];
libxml_clear_errors();
$relaxOk = $doc->relaxNGValidate('/tmp/definitely-missing-relaxng-xyz.rng');
restore_error_handler();
$relaxWarnings = $warnings;
$relaxErrors = libxml_get_errors();

echo 'schema_ok=' . ($schemaOk ? '1' : '0') . "\n";
echo 'schema_php=' . count($schemaWarnings) . "\n";
if (isset($schemaWarnings[0])) {
    echo 'schema_w0=' . $schemaWarnings[0] . "\n";
}
echo 'schema_libxml=' . count($schemaErrors) . "\n";
if (isset($schemaErrors[0], $schemaErrors[1])) {
    echo 'schema_e0=' . $schemaErrors[0]->level . ':' . $schemaErrors[0]->code . ':' . trim($schemaErrors[0]->message) . "\n";
    echo 'schema_e1=' . $schemaErrors[1]->level . ':' . $schemaErrors[1]->code . ':' . trim($schemaErrors[1]->message) . "\n";
}

echo 'relax_ok=' . ($relaxOk ? '1' : '0') . "\n";
echo 'relax_php=' . count($relaxWarnings) . "\n";
if (isset($relaxWarnings[0])) {
    echo 'relax_w0=' . $relaxWarnings[0] . "\n";
}
echo 'relax_libxml=' . count($relaxErrors) . "\n";
if (isset($relaxErrors[0], $relaxErrors[1])) {
    echo 'relax_e0=' . $relaxErrors[0]->level . ':' . $relaxErrors[0]->code . ':' . trim($relaxErrors[0]->message) . "\n";
    echo 'relax_e1=' . $relaxErrors[1]->level . ':' . $relaxErrors[1]->code . ':' . trim($relaxErrors[1]->message) . "\n";
}
