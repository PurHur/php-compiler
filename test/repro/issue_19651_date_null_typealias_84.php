<?php
// #19651 — date/gmdate/strtotime(null) TypeError on PHP_COMPILER_PROFILE=8.4
foreach (['date' => static fn () => date(null), 'gmdate' => static fn () => gmdate(null), 'strtotime' => static fn () => strtotime(null)] as $n => $fn) {
    echo "$n: ";
    try {
        var_export($fn());
        echo " COERCED\n";
    } catch (TypeError $e) {
        echo "TypeError\n";
    }
}
