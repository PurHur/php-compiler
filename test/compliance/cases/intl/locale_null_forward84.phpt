--TEST--
intl locale_get_*/canonicalize/display_name(null) TypeError on 8.4 forward (#21078)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
if (!function_exists('locale_get_primary_language') || !class_exists('Locale', false)) {
    die("skip ext/intl Locale parsers not available");
}
foreach ([
    'primary' => static fn () => locale_get_primary_language(null),
    'region' => static fn () => locale_get_region(null),
    'script' => static fn () => locale_get_script(null),
    'canonicalize' => static fn () => locale_canonicalize(null),
    'display_name' => static fn () => locale_get_display_name(null),
    'method_primary' => static fn () => Locale::getPrimaryLanguage(null),
    'method_region' => static fn () => Locale::getRegion(null),
    'method_script' => static fn () => Locale::getScript(null),
    'method_canonicalize' => static fn () => Locale::canonicalize(null),
    'method_display' => static fn () => Locale::getDisplayName(null),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' COERCED ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $name, ' TypeError';
        if (false !== strpos($e->getMessage(), 'null given')
            || false !== strpos($e->getMessage(), 'must be of type string')) {
            echo ' null';
        }
        echo "\n";
    }
}
echo 'ok_primary=', var_export(locale_get_primary_language('en_US'), true), "\n";
echo 'ok_region=', var_export(locale_get_region('en_US'), true), "\n";
echo 'ok_script=', var_export(locale_get_script('zh-Hans-CN'), true), "\n";
$canon = locale_canonicalize('en-us');
echo 'ok_canon=', (int) (is_string($canon) && '' !== $canon), "\n";
?>
--EXPECT--
primary TypeError null
region TypeError null
script TypeError null
canonicalize TypeError null
display_name TypeError null
method_primary TypeError null
method_region TypeError null
method_script TypeError null
method_canonicalize TypeError null
method_display TypeError null
ok_primary='en'
ok_region='US'
ok_script='Hans'
ok_canon=1
