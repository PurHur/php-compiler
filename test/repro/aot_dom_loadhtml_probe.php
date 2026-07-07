<?php

declare(strict_types=1);

$d = new DOMDocument();
$d->loadHTML('<html><body><div id="x"></div></body></html>');
$div = $d->getElementById('x');
echo null === $div ? "no_div\n" : "div_ok\n";
