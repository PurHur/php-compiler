<?php
// Issue #3267 repro — json_decode depth/flags/JSON_THROW_ON_ERROR
$bad = '{invalid';
try {
    json_decode($bad, true, 512, JSON_THROW_ON_ERROR);
    echo "no-throw\n";
} catch (JsonException $e) {
    echo "caught\n";
}

var_export(json_decode('{"a":{"b":1}}', true, 1));
echo " err=", json_last_error(), "\n";
