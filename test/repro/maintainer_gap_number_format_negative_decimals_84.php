<?php

declare(strict_types=1);

try {
    number_format(1.5, -1);
    echo "fail: no exception\n";
} catch (ValueError $e) {
    echo "ok: ".$e->getMessage()."\n";
}
