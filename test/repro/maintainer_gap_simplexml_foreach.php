<?php
declare(strict_types=1);

$xml = simplexml_load_string('<root><a>1</a><b>2</b></root>');
if (false === $xml) {
    echo "load_failed\n";
    exit(1);
}
if (!($xml instanceof Traversable)) {
    echo "not_traversable\n";
    exit(1);
}
$collected = [];
foreach ($xml as $name => $child) {
    $collected[] = (string) $name.(string) $child;
}
if ($collected !== ['a1', 'b2']) {
    echo 'bad:'.implode(',', $collected)."\n";
    exit(1);
}
echo "ok\n";
