--TEST--
FILE_NO_DEFAULT_CONTEXT constant — defined()/constant() parity (#14586, ext/standard/file.c)
--FILE--
<?php
echo defined('FILE_NO_DEFAULT_CONTEXT') && FILE_NO_DEFAULT_CONTEXT === 16 ? "const_ok\n" : "const_bad\n";
echo constant('FILE_NO_DEFAULT_CONTEXT') === 16 ? "fetch_ok\n" : "fetch_bad\n";
--EXPECT--
const_ok
fetch_ok
