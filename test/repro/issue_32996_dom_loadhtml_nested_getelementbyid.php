<?php
// #32996 — AOT loadHTML full html/body document + getElementById
$d = new DOMDocument();
$ok = $d->loadHTML('<html><body><p id="x">hi</p></body></html>');
echo $ok ? "ok\n" : "fail\n";
$e = $d->getElementById('x');
if ($e === null) {
    echo "null\n";
} else {
    echo $e->textContent . "\n";
}
