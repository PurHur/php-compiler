<?php
// Repro #20556 / #27593 — Dom\HTMLDocument / Dom\XMLDocument Document API
// Run with PHP_COMPILER_PROFILE=8.5 (getElementsByClassName is PHP 8.5+).
$d = Dom\HTMLDocument::createFromString('<html><body><p class="x y">x</p></body></html>');
foreach ([
    'createElement', 'createElementNS', 'createTextNode', 'createComment',
    'createDocumentFragment', 'createAttribute', 'getElementsByTagName',
    'getElementsByTagNameNS', 'getElementsByClassName', 'importNode', 'adoptNode',
    'saveXml', 'saveXmlFile', 'saveHtmlFile', 'append', 'prepend', 'replaceChildren',
    'saveHtml', 'getElementById',
] as $m) {
    echo $m, ': ', method_exists($d, $m) ? 'yes' : 'NO', PHP_EOL;
}
$el = $d->createElement('span');
echo 'createElement: ', $el->nodeName, ' class=', get_class($el), PHP_EOL;
$list = $d->getElementsByTagName('p');
echo 'getElementsByTagName: ', $list->length, PHP_EOL;
$byClass = $d->getElementsByClassName('x');
echo 'getElementsByClassName: ', $byClass->length, PHP_EOL;
$xml = $d->saveXml();
echo 'saveXml: ', (is_string($xml) && str_contains($xml, '<html') && str_contains($xml, '<p')) ? 'ok' : 'fail', PHP_EOL;

$src = Dom\HTMLDocument::createFromString('<div id="imp">z</div>');
$imported = $d->importNode($src->body->firstElementChild, true);
$d->body->appendChild($imported);
echo 'importNode: ', $imported->nodeName, PHP_EOL;

$path = sys_get_temp_dir() . '/phpc_20556_' . getmypid() . '.xml';
$n = $d->saveXmlFile($path);
echo 'saveXmlFile: ', (is_int($n) && $n > 0 && is_file($path)) ? 'ok' : 'fail', PHP_EOL;
@unlink($path);
$htmlPath = sys_get_temp_dir() . '/phpc_20556_' . getmypid() . '.html';
$hn = $d->saveHtmlFile($htmlPath);
echo 'saveHtmlFile: ', (is_int($hn) && $hn > 0 && is_file($htmlPath)) ? 'ok' : 'fail', PHP_EOL;
@unlink($htmlPath);

$x = Dom\XMLDocument::createFromString('<root><a class="c">1</a></root>');
foreach (['createElement', 'getElementsByTagName', 'saveXml', 'importNode', 'adoptNode'] as $m) {
    echo 'xml_', $m, ': ', method_exists($x, $m) ? 'yes' : 'NO', PHP_EOL;
}
$xe = $x->createElement('b');
echo 'xml_createElement: ', $xe->nodeName, ' class=', get_class($xe), PHP_EOL;
echo 'xml_getElementsByTagName: ', $x->getElementsByTagName('a')->length, PHP_EOL;
echo 'xml_saveXml: ', (is_string($x->saveXml()) && str_contains($x->saveXml(), '<root>')) ? 'ok' : 'fail', PHP_EOL;
$other = Dom\XMLDocument::createFromString('<z/>');
$adopted = $x->adoptNode($other->documentElement);
echo 'xml_adoptNode: ', $adopted->nodeName, PHP_EOL;
