--TEST--
AOT: ++ on top-level fopen php://memory resource TypeErrors (#23777)
--FILE--
<?php
$fh = fopen('php://memory', 'r+');
++$fh;
echo "no error\n";
?>
--EXPECTF--
%aCannot increment resource%a
--EXIT--
255
