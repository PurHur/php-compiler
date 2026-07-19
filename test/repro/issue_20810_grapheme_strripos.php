<?php

declare(strict_types=1);

// Issue #20810 — grapheme_strripos (php-src); strchr/strrchr are not in php-src.
$s = "ab😊cd😊x";
foreach (['grapheme_strchr', 'grapheme_strrchr', 'grapheme_strripos', 'grapheme_strpos'] as $f) {
    echo $f.'='.(function_exists($f) ? 'yes' : 'no')."\n";
}
if (function_exists('grapheme_strripos')) {
    var_export(grapheme_strripos($s, '😊'));
    echo "\n";
    var_export(grapheme_strripos('abCdE', 'c'));
    echo "\n";
}
