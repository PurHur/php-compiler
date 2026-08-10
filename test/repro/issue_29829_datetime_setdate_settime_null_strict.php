<?php

declare(strict_types=1);

try {
    (new DateTime('2020-01-01'))->setDate(null, 1, 1);
    echo "fail:setDate\n";
} catch (TypeError $e) {
    echo 'ok:setDate:', $e->getMessage(), "\n";
}

try {
    (new DateTime('2020-01-01'))->setTime(null, 0);
    echo "fail:setTime\n";
} catch (TypeError $e) {
    echo 'ok:setTime:', $e->getMessage(), "\n";
}
