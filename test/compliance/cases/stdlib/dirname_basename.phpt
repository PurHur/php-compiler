--TEST--
stdlib dirname() and basename()
--FILE--
<?php
echo dirname('/var/www/index.php'), "\n";
echo dirname('relative/path/file.php'), "\n";
echo basename('/var/www/index.php'), "\n";
echo basename('relative/path/file.php'), "\n";
--EXPECT--
/var/www
relative/path
index.php
file.php
