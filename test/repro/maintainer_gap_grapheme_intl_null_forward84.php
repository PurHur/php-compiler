<?php

error_reporting(E_ALL);

$cases = [];

// grapheme_strlen(null) — Zend 8.4: E_DEPRECATED + 0
try {
    $r = grapheme_strlen(null);
    $cases['grapheme_strlen'] = ($r === 0) ? 'OK' : 'WRONG:' . var_export($r, true);
} catch (\TypeError $e) {
    $cases['grapheme_strlen'] = 'TypeError null';
}

// grapheme_substr(null, 0) — Zend 8.4: E_DEPRECATED + ''/false
try {
    $r = grapheme_substr(null, 0);
    $ok = ($r === '' || $r === false);
    $cases['grapheme_substr'] = $ok ? 'OK' : 'WRONG:' . var_export($r, true);
} catch (\TypeError $e) {
    $cases['grapheme_substr'] = 'TypeError null';
}

// grapheme_strpos(null, 'a') — Zend 8.4: E_DEPRECATED + false
try {
    $r = grapheme_strpos(null, 'a');
    $cases['grapheme_strpos'] = ($r === false) ? 'OK' : 'WRONG:' . var_export($r, true);
} catch (\TypeError $e) {
    $cases['grapheme_strpos'] = 'TypeError null';
}

// normalizer_normalize(null) — Zend 8.4: E_DEPRECATED + ''
try {
    $r = normalizer_normalize(null);
    $cases['normalizer_normalize'] = ($r === '') ? 'OK' : 'WRONG:' . var_export($r, true);
} catch (\TypeError $e) {
    $cases['normalizer_normalize'] = 'TypeError null';
}

foreach ($cases as $fn => $result) {
    echo "$fn:$result\n";
}
