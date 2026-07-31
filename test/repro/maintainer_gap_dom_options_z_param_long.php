<?php
/**
 * #25768 — DOMDocument int $options/$flags Z_PARAM_LONG coercion (ext/dom/document.c).
 */
$doc = new DOMDocument();

function try_label(string $label, callable $fn): void
{
    try {
        $r = $fn();
        if (is_bool($r)) {
            echo $label, '=', $r ? 'true' : 'false', "\n";
        } elseif (is_string($r)) {
            echo $label, '=', (str_contains($r, '<r') || str_contains($r, '<?xml') ? 'ok' : 'bad'), "\n";
        } else {
            echo $label, '=', var_export($r, true), "\n";
        }
    } catch (Throwable $e) {
        echo $label, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}

try_label('loadHTML_str', fn () => (new DOMDocument())->loadHTML('<p>x</p>', '0'));
try_label('loadHTML_float', fn () => (new DOMDocument())->loadHTML('<p>x</p>', 1.5));
try_label('loadHTML_bool', fn () => (new DOMDocument())->loadHTML('<p>x</p>', true));
try_label('loadHTML_null', fn () => (new DOMDocument())->loadHTML('<p>x</p>', null));
try_label('loadXML_str', fn () => (new DOMDocument())->loadXML('<r/>', '0'));
try_label('saveXML_str', function () use ($doc) {
    $doc->loadXML('<r/>');

    return $doc->saveXML(null, '0');
});
try_label('xinclude_str', function () use ($doc) {
    $doc->loadXML('<r/>');

    return $doc->xinclude('0');
});
$xsd = '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">'
    . '<xs:element name="r" type="xs:string"/></xs:schema>';
$d2 = new DOMDocument();
$d2->loadXML('<r>x</r>');
try_label('schema_str', fn () => $d2->schemaValidateSource($xsd, '0'));
try_label('loadHTML_array', fn () => (new DOMDocument())->loadHTML('<p>x</p>', []));
