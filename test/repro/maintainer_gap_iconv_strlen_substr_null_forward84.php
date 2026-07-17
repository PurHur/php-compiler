<?php
foreach ([
    'strlen' => static fn () => iconv_strlen(null),
    'substr' => static fn () => iconv_substr(null, 0, 1),
    'strpos' => static fn () => iconv_strpos(null, 'a'),
] as $label => $fn) {
    try {
        $result = $fn();
        echo $label, '=COERCED ', var_export($result, true), "\n";
    } catch (Throwable $e) {
        echo $label, '=', get_class($e), "\n";
    }
}
