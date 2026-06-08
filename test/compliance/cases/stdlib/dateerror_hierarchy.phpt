--TEST--
stdlib DateError hierarchy — PHP 8.3 Error-branch date classes (#7276, ext/date/php_date.h)
--FILE--
<?php
var_export(class_exists('DateError', false));
echo "\n";
var_export(class_exists('DateObjectError', false));
echo "\n";
var_export(class_exists('DateRangeError', false));
echo "\n";
var_export(is_subclass_of('DateObjectError', 'DateError'));
echo "\n";
var_export(is_subclass_of('DateRangeError', 'DateError'));
echo "\n";
try {
    throw new DateRangeError('Epoch doesn\'t fit in a PHP integer');
} catch (DateError $e) {
    echo 'catch DateError ok', "\n";
}
class BadDateTime extends DateTime
{
    public function __construct()
    {
    }
}
try {
    $dt = new BadDateTime();
    $dt->getTimestamp();
    echo "uninit no throw\n";
} catch (DateObjectError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
true
true
true
catch DateError ok
Object of type BadDateTime has not been correctly initialized by calling parent::__construct() in its constructor
