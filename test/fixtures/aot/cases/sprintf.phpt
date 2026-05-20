--TEST--
AOT: sprintf() via LLVM (%s, %d, %f, %%)
--FILE--
<?php
echo sprintf('id=%d name=%s', 9, 'web'), "\n";
echo sprintf('ok=%%'), "\n";
--EXPECT--
id=9 name=web
ok=%
