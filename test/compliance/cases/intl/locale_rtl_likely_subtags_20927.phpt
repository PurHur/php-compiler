--TEST--
Locale::isRightToLeft / addLikelySubtags / minimizeSubtags on PROFILE=8.5 (#20927)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale RTL/likely subtags withheld until extension_loaded(\'intl\')';
}
if (!\PHPCompiler\CompilerVersion::advertisesLocaleRtlAndLikelySubtags()) {
    die('skip Locale RTL/likely subtags requires PHP_COMPILER_PROFILE=8.5');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);

foreach (['isRightToLeft', 'addLikelySubtags', 'minimizeSubtags'] as $m) {
    echo "Locale::$m=", method_exists('Locale', $m) ? 'yes' : 'NO', "\n";
}
foreach (['locale_is_right_to_left', 'locale_add_likely_subtags', 'locale_minimize_subtags'] as $f) {
    echo "$f=", function_exists($f) ? 'yes' : 'NO', "\n";
}

echo 'rtl_ar=', Locale::isRightToLeft('ar') ? '1' : '0', "\n";
echo 'rtl_en=', Locale::isRightToLeft('en') ? '1' : '0', "\n";
echo 'rtl_he=', locale_is_right_to_left('he') ? '1' : '0', "\n";

echo 'likely_en=', Locale::addLikelySubtags('en'), "\n";
echo 'likely_ar=', locale_add_likely_subtags('ar'), "\n";
echo 'min_en=', Locale::minimizeSubtags('en_Latn_US'), "\n";
echo 'min_ar=', locale_minimize_subtags('ar_Arab_EG'), "\n";
echo 'round=', Locale::minimizeSubtags(Locale::addLikelySubtags('ja')), "\n";
?>
--EXPECT--
Locale::isRightToLeft=yes
Locale::addLikelySubtags=yes
Locale::minimizeSubtags=yes
locale_is_right_to_left=yes
locale_add_likely_subtags=yes
locale_minimize_subtags=yes
rtl_ar=1
rtl_en=0
rtl_he=1
likely_en=en_Latn_US
likely_ar=ar_Arab_EG
min_en=en
min_ar=ar
round=ja
