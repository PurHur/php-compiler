<?php
declare(strict_types=1);

$x = simplexml_load_string('<root><item id="1">a</item><item id="2">b</item></root>');
if (false === $x) {
    echo "load_failed\n";
    exit(1);
}
echo (string) $x->item[0], "\n";
echo (string) $x->item[1], "\n";
echo (string) $x->item[0]['id'], "\n";
echo "ok\n";
