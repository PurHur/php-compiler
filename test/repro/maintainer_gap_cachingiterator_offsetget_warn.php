<?php
/** Maintainer gap: CachingIterator::offsetGet missing key should Warning (php-src-strict). */
$c = new CachingIterator(new ArrayIterator(['a' => 1]), CachingIterator::FULL_CACHE);
foreach ($c as $_) {
}
$msg = null;
set_error_handler(function ($errno, $errstr) use (&$msg) {
    $msg = $errstr;
    return true;
});
$v = $c->offsetGet('x');
restore_error_handler();
echo 'value=';
var_export($v);
echo "\nwarning=";
echo $msg === null ? 'NULL' : $msg;
echo "\n";
