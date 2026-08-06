--TEST--
stdlib Dom\HTMLDocument / Dom\XMLDocument Document API — create/query/import/adopt/save (#20556, #27593)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument') || !class_exists('Dom\\XMLDocument')) {
    die('skip Dom\\HTMLDocument / Dom\\XMLDocument require PHP_COMPILER_PROFILE=8.4 (#20556)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = Dom\HTMLDocument::createFromString('<html><body><p class="x y">x</p></body></html>');
foreach ([
    'createElement', 'getElementsByTagName', 'getElementsByClassName',
    'importNode', 'adoptNode', 'saveXml', 'saveXmlFile', 'saveHtmlFile',
    'append', 'prepend', 'replaceChildren',
] as $m) {
    echo $m, '=', method_exists($d, $m) ? '1' : '0', ' ';
}
echo "\n";
$el = $d->createElement('span');
echo 'el=', $el->nodeName, ':', get_class($el), "\n";
echo 'tags=', $d->getElementsByTagName('p')->length, "\n";
// getElementsByClassName is PHP 8.5+ only (#27593) — withheld on PROFILE=8.4.
echo 'class_method=', method_exists($d, 'getElementsByClassName') ? '1' : '0', "\n";
$xml = $d->saveXml();
echo 'saveXml=', (is_string($xml) && str_contains($xml, '<html') && str_contains($xml, '<p')) ? 'ok' : 'fail', "\n";
$src = Dom\HTMLDocument::createFromString('<div id="imp">z</div>');
$imported = $d->importNode($src->body->firstElementChild, true);
$d->body->appendChild($imported);
echo 'import=', $imported->nodeName, "\n";
$path = sys_get_temp_dir() . '/phpc_c20556_' . getmypid() . '.xml';
$n = $d->saveXmlFile($path);
echo 'saveXmlFile=', (is_int($n) && $n > 0 && is_file($path)) ? 'ok' : 'fail', "\n";
@unlink($path);
$htmlPath = sys_get_temp_dir() . '/phpc_c20556_' . getmypid() . '.html';
$hn = $d->saveHtmlFile($htmlPath);
echo 'saveHtmlFile=', (is_int($hn) && $hn > 0 && is_file($htmlPath)) ? 'ok' : 'fail', "\n";
@unlink($htmlPath);

$x = Dom\XMLDocument::createFromString('<root><a>1</a></root>');
foreach (['createElement', 'getElementsByTagName', 'saveXml', 'importNode', 'adoptNode'] as $m) {
    echo 'xml_', $m, '=', method_exists($x, $m) ? '1' : '0', ' ';
}
echo "\n";
$xe = $x->createElement('b');
echo 'xml_el=', $xe->nodeName, ':', get_class($xe), "\n";
echo 'xml_tags=', $x->getElementsByTagName('a')->length, "\n";
echo 'xml_saveXml=', (is_string($x->saveXml()) && str_contains($x->saveXml(), '<root>')) ? 'ok' : 'fail', "\n";
$other = Dom\XMLDocument::createFromString('<z/>');
$adopted = $x->adoptNode($other->documentElement);
echo 'xml_adopt=', $adopted->nodeName, "\n";
?>
--EXPECT--
createElement=1 getElementsByTagName=1 getElementsByClassName=0 importNode=1 adoptNode=1 saveXml=1 saveXmlFile=1 saveHtmlFile=1 append=1 prepend=1 replaceChildren=1 
el=SPAN:Dom\HTMLElement
tags=1
class_method=0
saveXml=ok
import=DIV
saveXmlFile=ok
saveHtmlFile=ok
xml_createElement=1 xml_getElementsByTagName=1 xml_saveXml=1 xml_importNode=1 xml_adoptNode=1 
xml_el=b:Dom\Element
xml_tags=1
xml_saveXml=ok
xml_adopt=z
