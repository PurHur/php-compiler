--TEST--
AOT: session_name() link smoke after session_name_storage runtime (#1184)
--FILE--
<?php
echo session_name() === 'PHPSESSID' ? 'default' : 'set', "\n";
echo "ok\n";
--EXPECT--
default
ok
