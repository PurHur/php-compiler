<?php
// Repro #20802 — msgfmt_parse / msgfmt_parse_message (+ pattern/locale/error) procedurals
foreach ([
    'msgfmt_parse',
    'msgfmt_parse_message',
    'msgfmt_get_locale',
    'msgfmt_get_pattern',
    'msgfmt_set_pattern',
    'msgfmt_get_error_code',
    'msgfmt_get_error_message',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
$mf = MessageFormatter::create('en_US', '{0,number}');
$oop = $mf->parse('1,234.5');
$proc = msgfmt_parse($mf, '1,234.5');
echo 'match_parse=', (int) ($oop === $proc), "\n";
$oopMsg = MessageFormatter::parseMessage('en_US', '{0,number}', '1,234.5');
$procMsg = msgfmt_parse_message('en_US', '{0,number}', '1,234.5');
echo 'match_parse_message=', (int) ($oopMsg === $procMsg), "\n";
echo 'locale=', msgfmt_get_locale($mf), "\n";
echo 'pattern=', msgfmt_get_pattern($mf), "\n";
echo 'set_pattern=', (int) msgfmt_set_pattern($mf, '{0,number,integer}'), "\n";
echo 'pattern2=', msgfmt_get_pattern($mf), "\n";
echo 'err=', msgfmt_get_error_code($mf), ' msg=', msgfmt_get_error_message($mf), "\n";
