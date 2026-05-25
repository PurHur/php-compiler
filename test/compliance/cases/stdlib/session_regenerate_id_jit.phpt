--TEST--
stdlib session_regenerate_id() JIT (issue #1186, #1056)
--FILE--
<?php
session_start();
$old = session_id();
echo session_regenerate_id(true) ? 'ok' : 'no', "\n";
$new = session_id();
echo ($old !== $new && strlen($new) === 32) ? 'rotated' : 'fail', "\n";
--EXPECT--
ok
rotated
