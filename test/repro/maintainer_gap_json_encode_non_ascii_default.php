<?php

declare(strict_types=1);

$encoded = json_encode("\xE2\x82\xAC");
if ($encoded !== '"\\u20ac"') {
    echo "encoded={$encoded}\n";
    exit(1);
}
$raw = json_encode("\xE2\x82\xAC", JSON_UNESCAPED_UNICODE);
if ($raw !== '"€"') {
    echo "raw={$raw}\n";
    exit(1);
}
echo "ok\n";
