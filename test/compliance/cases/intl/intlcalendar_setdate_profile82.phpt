--TEST--
IntlCalendar::setDate/setDateTime withheld on PROFILE=8.2 (#22597)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
declare(strict_types=1);
// Soft-exit: BaseTest ignores --SKIPIF--.
if (!extension_loaded('intl') || !class_exists('IntlCalendar')) {
    echo "skip\n";
    exit(0);
}
echo 'setDate=', method_exists('IntlCalendar', 'setDate') ? 'Y' : 'N', "\n";
echo 'setDateTime=', method_exists('IntlCalendar', 'setDateTime') ? 'Y' : 'N', "\n";
?>
--EXPECT--
setDate=N
setDateTime=N
