<?php

declare(strict_types=1);

/** AOT: adopting a DOMDocument throws DOMException NOT_SUPPORTED_ERR (#19654). */

$d1 = new DOMDocument();
$d1->loadXML('<a/>');
$d2 = new DOMDocument();
$d2->loadXML('<b/>');

try {
    $d2->adoptNode($d1);
    echo "fail: no exception\n";
    exit(1);
} catch (DOMException $e) {
    echo 'code=', $e->getCode(), "\n";
    echo 'msg=', $e->getMessage(), "\n";
}
