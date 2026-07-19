--TEST--
msgfmt_parse/parse_message/get_locale/pattern/error_* procedural (#20802)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
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
echo 'val0=', $proc[0], "\n";
$oopMsg = MessageFormatter::parseMessage('en_US', '{0,number}', '1,234.5');
$procMsg = msgfmt_parse_message('en_US', '{0,number}', '1,234.5');
echo 'match_parse_message=', (int) ($oopMsg === $procMsg), "\n";
echo 'locale=', msgfmt_get_locale($mf), "\n";
echo 'pattern=', msgfmt_get_pattern($mf), "\n";
echo 'set_pattern=', (int) msgfmt_set_pattern($mf, '{0,number,integer}'), "\n";
echo 'pattern2=', msgfmt_get_pattern($mf), "\n";
echo 'err=', msgfmt_get_error_code($mf), "\n";
echo 'msg=', msgfmt_get_error_message($mf), "\n";
?>
--EXPECT--
msgfmt_parse=1
msgfmt_parse_message=1
msgfmt_get_locale=1
msgfmt_get_pattern=1
msgfmt_set_pattern=1
msgfmt_get_error_code=1
msgfmt_get_error_message=1
match_parse=1
val0=1234.5
match_parse_message=1
locale=en_US
pattern={0,number}
set_pattern=1
pattern2={0,number,integer}
err=0
msg=U_ZERO_ERROR
