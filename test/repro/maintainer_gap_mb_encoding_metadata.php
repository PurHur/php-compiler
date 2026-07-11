<?php

declare(strict_types=1);

$checks = [
    function_exists('mb_http_output'),
    function_exists('mb_detect_order'),
    function_exists('mb_substitute_character'),
    function_exists('mb_preferred_mime_name'),
    function_exists('mb_encoding_aliases'),
    mb_http_output() === 'UTF-8',
    mb_detect_order() === ['ASCII', 'UTF-8'],
    mb_substitute_character() === 63,
    mb_preferred_mime_name('UTF-8') === 'UTF-8',
    mb_encoding_aliases('UTF-8') === ['utf8'],
    mb_http_output('SJIS') === true && mb_http_output() === 'SJIS',
    mb_detect_order(['UTF-8', 'ASCII']) === true
        && mb_detect_order() === ['UTF-8', 'ASCII'],
    mb_substitute_character(0xFFFD) === true
        && mb_substitute_character() === 0xFFFD,
    mb_substitute_character('long') === true
        && mb_substitute_character() === 'long',
];

$ok = true;
foreach ($checks as $check) {
    if (!$check) {
        $ok = false;
        break;
    }
}

mb_http_output('UTF-8');
mb_detect_order();
mb_substitute_character(63);

echo $ok ? "ok\n" : "fail\n";
