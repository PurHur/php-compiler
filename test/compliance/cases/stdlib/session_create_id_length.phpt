--TEST--
stdlib session_create_id() uses php-src 26-char sid alphabet (#10864, ext/session/session.c)
--FILE--
<?php
$id = session_create_id();
echo strlen($id), "\n";
echo (int) (26 === strlen($id)), "\n";
echo (int) (1 === preg_match('/^[0-9a-zA-Z,-]+$/', $id)), "\n";
--EXPECT--
26
1
1
