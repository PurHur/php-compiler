--TEST--
stdlib bin2hex()
--FILE--
<?php
echo bin2hex(''), "\n";
echo bin2hex("\x00\x0f\xff"), "\n";
echo bin2hex('ab'), "\n";
--EXPECT--

000fff
6162
