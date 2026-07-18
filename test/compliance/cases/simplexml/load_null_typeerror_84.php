<?php
// Guard #20352 — simplexml_load_string/file(null) TypeError under PROFILE=8.4
foreach (['simplexml_load_string', 'simplexml_load_file'] as $fn) {
    try {
        $fn(null);
        echo "fail {$fn}\n";
    } catch (TypeError $e) {
        echo "ok {$fn}\n";
    }
}
