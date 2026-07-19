--TEST--
AOT: ftp_connect(null) — TypeError on 8.4 forward profile (#20484, ext/ftp/ftp.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
ftp_connect(null);
--EXPECT--
--EXPECT_EXIT--
255
