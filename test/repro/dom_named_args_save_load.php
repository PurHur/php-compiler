<?php
declare(strict_types=1);

// Repro for #25182 — DOM/XML named args vs Zend php_dom.stub.php
$d = new DOMDocument();
echo 'saveXML=' . (str_contains($d->saveXML(node: null), '<') ? 'ok' : 'bad') . "\n";
echo 'saveHTML=' . (is_string($d->saveHTML(node: null)) ? 'ok' : 'bad') . "\n";
echo 'saveXML_opts=' . (str_contains($d->saveXML(options: 0), '<?xml') ? 'ok' : 'bad') . "\n";
$d->loadXML('<r/>');
echo 'saveXML_node=' . (str_contains($d->saveXML(node: $d->documentElement), '<r') ? 'ok' : 'bad') . "\n";
echo 'loadXML=' . ($d->loadXML(source: '<r><a/></r>', options: 0) ? 'ok' : 'bad') . "\n";
$tmp = tempnam(sys_get_temp_dir(), 'dom25182');
file_put_contents($tmp, '<r/>');
echo 'load=' . ($d->load(filename: $tmp, options: 0) ? 'ok' : 'bad') . "\n";
@unlink($tmp);
$d->loadXML('<r><a/></r>');
echo 'clone=' . $d->documentElement->cloneNode(deep: true)->tagName . "\n";
echo 'item=' . $d->documentElement->childNodes->item(index: 0)->tagName . "\n";
$xp = new DOMXPath($d);
$xp->registerPhpFunctions(restrict: null);
echo "registerPhp=ok\n";
