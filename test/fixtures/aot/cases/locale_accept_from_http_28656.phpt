--TEST--
Locale::acceptFromHttp() AOT Accept-Language (#28656)
--SKIPIF--
<?php if (!extension_loaded('intl')) die('skip intl required'); ?>
--FILE--
<?php
echo Locale::acceptFromHttp('en-US,en;q=0.9,fr;q=0.8'), "\n";
--EXPECT--
en_US
