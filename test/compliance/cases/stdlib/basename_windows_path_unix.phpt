--TEST--
stdlib basename()/dirname() — Windows-style path on Unix (#10766)
--FILE--
<?php
echo basename('C:\\x\\y'), "\n";
echo dirname('C:\\x\\y'), "\n";
echo basename('/var/www/index.php'), "\n";
echo dirname('/var/www/index.php'), "\n";
--EXPECT--
C:\x\y
.
index.php
/var/www
