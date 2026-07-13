<?php

try {
    json_decode('{', null, 512, JSON_THROW_ON_ERROR);
    echo "no throw\n";
} catch (JsonException $e) {
    echo "JsonException\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

