--TEST--
numfmt_get_attribute/set_attribute/get_pattern/get_locale/error_* procedural (#20800)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$fmt = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
foreach ([
    'numfmt_get_attribute',
    'numfmt_set_attribute',
    'numfmt_get_pattern',
    'numfmt_set_pattern',
    'numfmt_get_locale',
    'numfmt_get_error_code',
    'numfmt_get_error_message',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
$oop = $fmt->getAttribute(NumberFormatter::FRACTION_DIGITS);
$proc = numfmt_get_attribute($fmt, NumberFormatter::FRACTION_DIGITS);
echo 'match_attr=', (int) ($oop === $proc), "\n";
echo 'set=', (int) numfmt_set_attribute($fmt, NumberFormatter::FRACTION_DIGITS, 3), "\n";
echo 'after=', (int) numfmt_get_attribute($fmt, NumberFormatter::FRACTION_DIGITS), "\n";
echo 'locale=', numfmt_get_locale($fmt), "\n";
echo 'locale_oop=', $fmt->getLocale(), "\n";
echo 'err=', numfmt_get_error_code($fmt), "\n";
echo 'msg=', numfmt_get_error_message($fmt), "\n";
$patBefore = numfmt_get_pattern($fmt);
echo 'pattern_type=', gettype($patBefore), "\n";
echo 'set_pattern=', (int) numfmt_set_pattern($fmt, '#,##0.00'), "\n";
?>
--EXPECT--
numfmt_get_attribute=1
numfmt_set_attribute=1
numfmt_get_pattern=1
numfmt_set_pattern=1
numfmt_get_locale=1
numfmt_get_error_code=1
numfmt_get_error_message=1
match_attr=1
set=1
after=3
locale=en_US
locale_oop=en_US
err=0
msg=U_ZERO_ERROR
pattern_type=string
set_pattern=1
