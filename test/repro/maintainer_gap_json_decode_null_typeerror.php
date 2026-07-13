<?php

declare(strict_types=1);

// #18601 — json_decode(null) must TypeError (ext/json/php_json.c)
try {
    json_decode(null);
    echo "json_decode: no_ex\n";
} catch (TypeError $e) {
    echo "json_decode: TypeError\n";
}
