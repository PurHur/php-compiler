<?php
// Guard #21061 / #21282 / #21313 / #21516 / #24209 / #24207 — mb_strwidth/search/case/scrub family null under PROFILE=8.4
// Soft-null: mb_strtoupper/mb_convert_case/mb_str* search (#21313); mb_scrub/mb_encode_mimeheader (#21516/#21430);
// mb_convert_kana (#24209); mb_str_split (#24207). Hard TypeError: mb_decode_mimeheader.
$cases = [
    'mb_strwidth' => static fn () => mb_strwidth(null),
    'mb_strstr' => static fn () => mb_strstr(null, 'a'),
    'mb_stristr' => static fn () => mb_stristr(null, 'a'),
    'mb_strrchr' => static fn () => mb_strrchr(null, 'a'),
    'mb_stripos' => static fn () => mb_stripos(null, 'a'),
    'mb_strripos' => static fn () => mb_strripos(null, 'a'),
    'mb_strrpos' => static fn () => mb_strrpos(null, 'a'),
    'mb_convert_case' => static fn () => mb_convert_case(null, MB_CASE_UPPER),
    'mb_strtoupper' => static fn () => mb_strtoupper(null),
    'mb_scrub' => static fn () => mb_scrub(null),
    'mb_str_split' => static fn () => mb_str_split(null),
    'mb_encode_mimeheader' => static fn () => mb_encode_mimeheader(null),
    'mb_decode_mimeheader' => static fn () => mb_decode_mimeheader(null),
    'mb_convert_kana' => static fn () => mb_convert_kana(null),
];
$softOk = array_fill_keys([
    'mb_strwidth', 'mb_strstr', 'mb_stristr', 'mb_strrchr', 'mb_stripos', 'mb_strripos', 'mb_strrpos',
    'mb_convert_case', 'mb_strtoupper', 'mb_scrub', 'mb_encode_mimeheader',
    // Zend Z_PARAM_STR soft-null + DEP (#24209; was incorrectly TypeError-expecting).
    'mb_convert_kana',
    // Zend Z_PARAM_STR soft-null + DEP (#24207; null → '' → []).
    'mb_str_split',
], true);
foreach ($cases as $name => $fn) {
    try {
        $r = $fn();
        if (isset($softOk[$name])) {
            echo "$name: OK\n";
            continue;
        }
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo $name.': '.$e->getMessage()."\n";
    }
}
