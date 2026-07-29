<?php

declare(strict_types=1);

// Real adoptNode requires PHP 8.3+ (#24995). Run with PHP_COMPILER_PROFILE=8.3 (or 8.4).

$d1 = new DOMDocument();
$d1->loadXML('<a><n>t</n></a>');
$d2 = new DOMDocument();
$d2->loadXML('<b/>');
$n = $d1->documentElement->firstChild;

if (!method_exists($d2, 'adoptNode')) {
    echo "fail: adoptNode not registered\n";
    exit(1);
}

$a = $d2->adoptNode($n);
if ($a->nodeName !== 'n') {
    echo 'fail: nodeName=', $a->nodeName, "\n";
    exit(1);
}
if ($d1->saveXML($d1->documentElement) !== '<a/>') {
    echo 'fail: d1=', $d1->saveXML($d1->documentElement), "\n";
    exit(1);
}
if ($a !== $n) {
    echo "fail: adopt should return same object\n";
    exit(1);
}
if ($a->ownerDocument !== $d2) {
    echo "fail: ownerDocument not target\n";
    exit(1);
}
$d2->documentElement->appendChild($a);
if ($d2->saveXML($d2->documentElement) !== '<b><n>t</n></b>') {
    echo 'fail: d2=', $d2->saveXML($d2->documentElement), "\n";
    exit(1);
}

try {
    $d2->adoptNode($d1);
    echo "fail: document adopt should reject\n";
    exit(1);
} catch (DOMException $e) {
    if (DOMException::NOT_SUPPORTED_ERR !== $e->getCode()) {
        echo 'fail: wrong code ', $e->getCode(), "\n";
        exit(1);
    }
}

echo "ok\n";
