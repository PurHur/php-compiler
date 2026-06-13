<?php

foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
    try {
        $fn(1, 'a');
        echo "$fn: no throw\n";
    } catch (TypeError $e) {
        echo "$fn: TypeError\n";
    }
}
