<?php
// Repro #20800 — numfmt_* attribute/pattern/locale/error procedural aliases
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
echo 'match_attr=', (int) ($oop === $proc), ' val=', (int) $proc, "\n";
echo 'set=', (int) numfmt_set_attribute($fmt, NumberFormatter::FRACTION_DIGITS, 3), "\n";
echo 'after=', (int) numfmt_get_attribute($fmt, NumberFormatter::FRACTION_DIGITS), "\n";
echo 'locale=', numfmt_get_locale($fmt), "\n";
echo 'err=', numfmt_get_error_code($fmt), ' msg=', numfmt_get_error_message($fmt), "\n";
