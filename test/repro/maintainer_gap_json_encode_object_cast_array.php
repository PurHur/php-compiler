<?php

declare(strict_types=1);

$encoded = json_encode((object) [1, 2]);
if ($encoded !== '{"0":1,"1":2}') {
    echo "encoded={$encoded} err=" . json_last_error_msg() . "\n";
    exit(1);
}
echo "ok\n";
