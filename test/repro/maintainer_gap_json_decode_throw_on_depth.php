<?php

declare(strict_types=1);

try {
    json_decode(str_repeat('[', 10_000), true, 512, JSON_THROW_ON_ERROR);
    echo "fail: expected JsonException\n";
} catch (JsonException $e) {
    echo "ok\n";
}
