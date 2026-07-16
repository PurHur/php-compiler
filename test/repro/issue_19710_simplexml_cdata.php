<?php
/** Repro #19710 — simplexml_load_string must accept CDATA text (ext/simplexml/simplexml.c). */
$s = @simplexml_load_string('<r><![CDATA[ab]]></r>');
var_export($s === false);
echo "\n";
if ($s !== false) {
    echo (string) $s, "\n";
}

$mixed = @simplexml_load_string('<r>x<![CDATA[y]]>z</r>');
if ($mixed !== false) {
    echo (string) $mixed, "\n";
}

$special = @simplexml_load_string('<r><![CDATA[a<b>]]></r>');
if ($special !== false) {
    echo (string) $special, "\n";
}

$nested = @simplexml_load_string('<r><c><![CDATA[ab]]></c></r>');
if ($nested !== false) {
    echo (string) $nested->c, "\n";
}
