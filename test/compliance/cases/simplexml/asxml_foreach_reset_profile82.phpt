--TEST--
SimpleXMLElement::asXML() mid-foreach rewinds iterator under PROFILE=8.2 (php-src <8.4; #27717)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
$xml = simplexml_load_string('<root><a><b>1</b><b>2</b><b>3</b></a></root>');
$nodes = $xml->a->b;
$out = [];
$n = 0;
foreach ($nodes as $nodeData) {
    $out[] = (string) $nodeData;
    $nodes->asXml();
    if (++$n > 8) {
        $out[] = 'LOOP';
        break;
    }
}
echo implode(',', $out), "\n";

// Plain element (SXE_ITER_NONE) must not rewind — Zend prints 1,2,3.
$root = simplexml_load_string('<r><a>1</a><a>2</a><a>3</a></r>');
$out = [];
$n = 0;
foreach ($root as $child) {
    $out[] = (string) $child;
    $root->asXml();
    if (++$n > 8) {
        $out[] = 'LOOP';
        break;
    }
}
echo implode(',', $out), "\n";
?>
--EXPECT--
1,2,2,2,2,2,2,2,2,LOOP
1,2,3
