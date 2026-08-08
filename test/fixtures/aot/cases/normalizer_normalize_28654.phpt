--TEST--
Normalizer::normalize() AOT NFC combining acute (#28654)
--SKIPIF--
<?php if (!extension_loaded('intl')) die('skip intl required'); ?>
--FILE--
<?php
$s = "e\u{0301}";
echo Normalizer::normalize($s, Normalizer::FORM_C) === "é" ? "ok\n" : "bad\n";
echo bin2hex(Normalizer::normalize($s, Normalizer::FORM_C)), "\n";
--EXPECT--
ok
c3a9
