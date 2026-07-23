--TEST--
date DateTimeInterface method table matches Zend stub (#22609, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);
$m = get_class_methods('DateTimeInterface') ?: [];
sort($m);
echo implode(',', $m), "\n";
foreach (['format', 'diff', 'getTimestamp', 'getTimezone', 'getOffset', '__serialize', '__unserialize', '__wakeup'] as $name) {
    echo $name, '=', (int) method_exists('DateTimeInterface', $name), "\n";
}
?>
--EXPECT--
__serialize,__unserialize,__wakeup,diff,format,getOffset,getTimestamp,getTimezone
format=1
diff=1
getTimestamp=1
getTimezone=1
getOffset=1
__serialize=1
__unserialize=1
__wakeup=1
