--TEST--
NumberFormatter::format() AOT DECIMAL (#28648)
--SKIPIF--
<?php if (!extension_loaded('intl')) die('skip intl required'); ?>
--FILE--
<?php
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo $f->format(12.5), "\n";
--EXPECT--
12.5
