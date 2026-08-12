--TEST--
locale_accept_from_http soft-null without strict_types returns false (#29914)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
// No declare(strict_types=1) — Z_PARAM_STR soft-coerces null → "" → false.
echo 'oop=', var_export(Locale::acceptFromHttp(null), true), "\n";
echo 'proc=', var_export(locale_accept_from_http(null), true), "\n";
?>
--EXPECT--
oop=false
proc=false
