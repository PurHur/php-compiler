<?php
/**
 * Issue #24589: DateInterval::createFromDateString Reflection + named datetime.
 * php-src: ext/date/php_date.stub.php
 */
$r = new ReflectionMethod(DateInterval::class, 'createFromDateString');
echo 'name=', $r->getParameters()[0]->getName(), "\n";
try {
    $i = DateInterval::createFromDateString(datetime: '1 day');
    echo 'named_ok=', $i->format('%d'), "\n";
} catch (Throwable $e) {
    echo 'named_err=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    DateInterval::createFromDateString(time: '1 day');
    echo "time_ok\n";
} catch (Throwable $e) {
    echo 'time_err=', get_class($e), "\n";
}
