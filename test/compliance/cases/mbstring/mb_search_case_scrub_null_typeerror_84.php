<?php
// Guard #21061 — mb_strwidth/search/case/scrub family null TypeError under PROFILE=8.4
$cases = [
    'mb_strwidth' => static fn () => mb_strwidth(null),
    'mb_strstr' => static fn () => mb_strstr(null, 'a'),
    'mb_stristr' => static fn () => mb_stristr(null, 'a'),
    'mb_strrchr' => static fn () => mb_strrchr(null, 'a'),
    'mb_stripos' => static fn () => mb_stripos(null, 'a'),
    'mb_strripos' => static fn () => mb_strripos(null, 'a'),
    'mb_strrpos' => static fn () => mb_strrpos(null, 'a'),
    'mb_substr_count' => static fn () => mb_substr_count(null, 'a'),
    'mb_convert_case' => static fn () => mb_convert_case(null, MB_CASE_UPPER),
    'mb_scrub' => static fn () => mb_scrub(null),
    'mb_str_split' => static fn () => mb_str_split(null),
    'mb_encode_mimeheader' => static fn () => mb_encode_mimeheader(null),
    'mb_decode_mimeheader' => static fn () => mb_decode_mimeheader(null),
    'mb_convert_kana' => static fn () => mb_convert_kana(null),
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo $name.': '.$e->getMessage()."\n";
    }
}
