--TEST--
IntlBreakIterator preceding/following/isBoundary/getLocale (#20771)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlBreakIterator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--RUNFILE--
breakiterator_preceding_following.php
--EXPECT--
preceding=1
following=1
isBoundary=1
getLocale=1
preceding6=5
cur=5
following6=11
b5=1
b6=1
b7=0
actual=''
valid='en_US'
err=0:U_ZERO_ERROR
