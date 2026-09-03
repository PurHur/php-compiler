<?php
// Dom / SimpleXML / BcMath surfaces owned by Module::jitInit (#36204).
// Run with PHP_COMPILER_PROFILE=8.4 (DOCUMENT_POSITION_* + BcMath\Number).
echo (int) DOMNode::DOCUMENT_POSITION_DISCONNECTED, ',', (int) DOMNode::DOCUMENT_POSITION_FOLLOWING, "\n";
$sxe = simplexml_load_string('<a><b/></a>');
echo ($sxe instanceof Traversable) ? 'sxe' : 'no-sxe', "\n";
$n = new BcMath\Number('2');
echo 'num:', (string) $n, "\n";
$doc = new DOMDocument();
echo 'dom', "\n";
