--TEST--
AOT: putenv() then getenv() via libc
--FILE--
<?php
putenv('APP_ENV=production');
echo getenv('APP_ENV'), "\n";
--EXPECT--
production
