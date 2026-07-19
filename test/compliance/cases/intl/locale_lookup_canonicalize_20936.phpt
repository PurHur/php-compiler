--TEST--
Locale::lookup canonicalize longest-match (#20936 / php-src #72809)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale negotiate withheld until extension_loaded(\'intl\') (#19670/#20036)';
}
?>
--FILE--
<?php
// php.net Locale::lookup example — variant kept under canonicalize
$arr = ['de-DEVA', 'de-DE-1996', 'de', 'de-De'];
echo 'phpnet=', Locale::lookup($arr, 'de-DE-1996-x-prv1-prv2', true, 'en_US'), "\n";
echo 'phpnet_nocanon=', Locale::lookup($arr, 'de-DE-1996-x-prv1-prv2', false, 'en_US'), "\n";

// php-src bug #72809 — @keyword / ICU keyword form truncates at @ before language
echo 'at=', Locale::lookup(['en', 'en_US'], 'en_US@currency=eur;fw=mon;timezone=Europe/Berlin', true), "\n";
echo 'at_nocanon=', Locale::lookup(['en', 'en_US'], 'en_US@currency=eur;fw=mon;timezone=Europe/Berlin', false), "\n";
echo 'u=', Locale::lookup(['en', 'en-US'], 'en-US-u-cu-EUR-tz-deber-fw-mon', true), "\n";
?>
--EXPECT--
phpnet=de_de_1996
phpnet_nocanon=de-DE-1996
at=en_us
at_nocanon=en_US
u=en_us
