--TEST--
IntlDateFormatter::parseToCalendar withheld on PROFILE=8.2 (#22621)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
declare(strict_types=1);
// Soft-exit: BaseTest ignores --SKIPIF--.
if (!extension_loaded('intl') || !class_exists('IntlDateFormatter')) {
    echo "skip\n";
    exit(0);
}
echo 'parseToCalendar=', method_exists('IntlDateFormatter', 'parseToCalendar') ? 'Y' : 'N', "\n";
echo 'parse=', method_exists('IntlDateFormatter', 'parse') ? 'Y' : 'N', "\n";
?>
--EXPECT--
parseToCalendar=N
parse=Y
