--TEST--
Closure::fromCallable AOT — string + static method (#26788)
--FILE--
<?php
echo Closure::fromCallable('strlen')('abcd'), "\n";
echo Closure::fromCallable(['DateTime', 'createFromFormat'])('Y-m-d', '2020-01-02')->format('Y-m-d'), "\n";
?>
--EXPECT--
4
2020-01-02
