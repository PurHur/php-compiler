<?php
// repro: SimpleXML unset($sxe->child) via SimpleXmlVmRuntimeSupport (#36204)
$xml = simplexml_load_string('<root><a>1</a><b>2</b></root>');
unset($xml->a);
$left = [];
foreach ($xml->children() as $name => $_) {
    $left[] = (string) $name;
}
echo implode(',', $left);
echo ' ';
// XMLReader instance open keeps $this (php-src zim_xmlreader_open) — no ext import in lib
$r = new XMLReader();
$ok = $r->XML('<x>y</x>');
echo $ok ? 'reader-ok' : 'reader-fail';
echo ' ';
echo $r->read() ? 'read-ok' : 'read-fail';
echo PHP_EOL;
