--TEST--
AOT: loadHTML full html/body document getElementById (#32996)
--FILE--
<?php
$doc = new DOMDocument();
$ok = $doc->loadHTML('<html><body><p id="x">hi</p></body></html>');
echo $ok ? "ok\n" : "fail\n";
$found = $doc->getElementById('x');
echo null !== $found ? $found->textContent : 'null', "\n";
echo null === $doc->getElementById('missing') ? 'missing_null' : 'missing_found', "\n";
--EXPECT--
ok
hi
missing_null
