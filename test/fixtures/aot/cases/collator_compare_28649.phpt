--TEST--
Collator::compare() AOT UTF-8 (#28649)
--SKIPIF--
<?php if (!extension_loaded('intl')) die('skip intl required'); ?>
--FILE--
<?php
echo (new Collator('en_US'))->compare('a', 'b'), "\n";
--EXPECT--
-1
