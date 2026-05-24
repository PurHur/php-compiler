--TEST--
AOT: session_id() link smoke after session_id_storage runtime (#1183)
--FILE--
<?php
echo session_id() === '' ? 'empty' : 'set', "\n";
echo "ok\n";
--EXPECT--
empty
ok
