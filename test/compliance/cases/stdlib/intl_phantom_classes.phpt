--TEST--
stdlib intl OOP + locale_* withheld without host php-intl (#19670, re-#16214, #6171, #6366, #22691)
--FILE--
<?php
echo 'intl_loaded=', (int) extension_loaded('intl'), "\n";
echo 'locale=', (int) class_exists('Locale', false), "\n";
echo 'formatter=', (int) class_exists('IntlDateFormatter', false), "\n";
echo 'number=', (int) class_exists('NumberFormatter', false), "\n";
echo 'calendar=', (int) class_exists('IntlCalendar', false), "\n";
echo 'timezone=', (int) class_exists('IntlTimeZone', false), "\n";
echo 'collator=', (int) class_exists('Collator', false), "\n";
echo 'messageformatter=', (int) class_exists('MessageFormatter', false), "\n";
echo 'transliterator=', (int) class_exists('Transliterator', false), "\n";
echo 'resourcebundle=', (int) class_exists('ResourceBundle', false), "\n";
echo 'intlbreakiterator=', (int) class_exists('IntlBreakIterator', false), "\n";
echo 'intlrulebasedbreakiterator=', (int) class_exists('IntlRuleBasedBreakIterator', false), "\n";
echo 'intlpartsiterator=', (int) class_exists('IntlPartsIterator', false), "\n";
echo 'intliterator=', (int) class_exists('IntlIterator', false), "\n";
echo 'intlchar=', (int) class_exists('IntlChar', false), "\n";
echo 'uconverter=', (int) class_exists('UConverter', false), "\n";
echo 'spoofchecker=', (int) class_exists('Spoofchecker', false), "\n";
echo 'intllistformatter=', (int) class_exists('IntlListFormatter', false), "\n";
echo 'locale_get_default=', (int) function_exists('locale_get_default'), "\n";
try {
    Collator::create('en_US');
    echo "collator_no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    MessageFormatter::create('en_US', '{0}');
    echo "msgfmt_no_throw\n";
} catch (Throwable $e) {
    echo 'msgfmt_err=', get_class($e), "\n";
}
try {
    IntlDateFormatter::create('en_US', 0, 0, 'UTC', 1, 'yyyy-MM-dd');
    echo "formatter_no_throw\n";
} catch (Throwable $e) {
    echo 'formatter_err=', get_class($e), "\n";
}
?>
--EXPECT--
intl_loaded=0
locale=0
formatter=0
number=0
calendar=0
timezone=0
collator=0
messageformatter=0
transliterator=0
resourcebundle=0
intlbreakiterator=0
intlrulebasedbreakiterator=0
intlpartsiterator=0
intliterator=0
intlchar=0
uconverter=0
spoofchecker=0
intllistformatter=0
locale_get_default=0
Error: Class "Collator" not found
msgfmt_err=Error
formatter_err=Error
