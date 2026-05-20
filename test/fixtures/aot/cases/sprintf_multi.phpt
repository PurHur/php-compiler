--TEST--
AOT: multiple sprintf() calls with argv in one compilation unit
--FILE--
<?php
echo sprintf('id=%d name=%s', 9, 'web'), "\n";
echo sprintf('ok=%%'), "\n";
--EXPECT--
id=9 name=web
ok=%
