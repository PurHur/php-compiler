--TEST--
AOT: ftp_connect(null) — DEP+false on 8.4 forward profile (#21757, ext/ftp/ftp.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(@ftp_connect(null));
echo "\n";
--EXPECT--
false
