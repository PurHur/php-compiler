<?php

declare(strict_types=1);

$dom = new DOMDocument();
$dom->loadXML('<root>  <a/>  <b/>  </root>');
$dom->documentElement->normalize();
$out = $dom->saveXML($dom->documentElement);
$expected = '<root>  <a/>  <b/>  </root>';
if ($out !== $expected) {
    fwrite(STDERR, "expected: $expected\n");
    fwrite(STDERR, "got:      $out\n");
    exit(1);
}
echo "ok\n";
