<?php
// Repro #20360 — function_exists/method_exists/property_exists(null) TypeError under PROFILE=8.4
try {
    function_exists(null);
    echo "fail function_exists\n";
} catch (TypeError $e) {
    echo "ok function_exists\n";
}
try {
    method_exists('stdClass', null);
    echo "fail method_exists\n";
} catch (TypeError $e) {
    echo "ok method_exists\n";
}
try {
    property_exists('stdClass', null);
    echo "fail property_exists\n";
} catch (TypeError $e) {
    echo "ok property_exists\n";
}
try {
    class_exists(null);
    echo "fail class_exists\n";
} catch (TypeError $e) {
    echo "ok class_exists\n";
}
