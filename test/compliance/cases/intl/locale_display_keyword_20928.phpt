--TEST--
Locale::getDisplayKeyword / getDisplayKeywordValue on PROFILE=8.5 (#20928)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale display keyword withheld until extension_loaded(\'intl\')';
}
if (!\PHPCompiler\CompilerVersion::advertisesLocaleDisplayKeyword()) {
    die('skip Locale display keyword requires PHP_COMPILER_PROFILE=8.5');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);

foreach (['getDisplayKeyword', 'getDisplayKeywordValue'] as $m) {
    echo "Locale::$m=", method_exists('Locale', $m) ? 'yes' : 'NO', "\n";
}
foreach (['locale_get_display_keyword', 'locale_get_display_keyword_value'] as $f) {
    echo "$f=", function_exists($f) ? 'yes' : 'NO', "\n";
}

echo 'kw=', Locale::getDisplayKeyword('currency', 'en'), "\n";
echo 'coll=', locale_get_display_keyword('collation', 'en'), "\n";
echo 'val=', Locale::getDisplayKeywordValue('de_DE@currency=EUR', 'currency', 'en'), "\n";
echo 'val2=', locale_get_display_keyword_value('en_US@currency=USD', 'currency', 'en'), "\n";
?>
--EXPECT--
Locale::getDisplayKeyword=yes
Locale::getDisplayKeywordValue=yes
locale_get_display_keyword=yes
locale_get_display_keyword_value=yes
kw=Currency
coll=Sort Order
val=Euro
val2=US Dollar
