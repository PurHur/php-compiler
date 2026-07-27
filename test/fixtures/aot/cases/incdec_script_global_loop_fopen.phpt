--TEST--
AOT: ++ on script-global loop counter with live fopen handle (#23841)
--FILE--
<?php
$fh = fopen('php://memory', 'r+');
$acc = 0;
for ($i = 0; $i < 5; ++$i) {
    ++$acc;
}
echo "$acc\n";
fclose($fh);
?>
--EXPECT--
5
--EXIT--
0
