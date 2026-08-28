--TEST--
AOT: session_start($options) applies name + starts session (#33945, ext/session/session.c)
--FILE--
<?php
session_start(['name' => 'PHPCSSO', 'use_cookies' => 0]);
echo session_name(), '|', session_status(), "\n";
--EXPECTF--
PHPCSSO|2
Set-Cookie: PHPCSSO=%s; path=/
