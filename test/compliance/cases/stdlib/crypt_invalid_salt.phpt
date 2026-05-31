--TEST--
stdlib crypt() — invalid salt returns *0 sentinel (issue #3771; php-src crypt.c)
--FILE--
<?php
$r = crypt('secret', '');
echo $r, "\n";
$r = crypt('secret', '$2y$10$');
echo $r, "\n";
$r = crypt('secret', '!!!');
echo $r, "\n";
--EXPECT--
*0
*0
*0
