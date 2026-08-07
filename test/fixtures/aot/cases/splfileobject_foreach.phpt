--TEST--
AOT: SplFileObject foreach line iteration (#28709)
--FILE--
<?php
file_put_contents('/tmp/phpc_sfo_foreach_aot.txt', "a\nb\n");
$f = new SplFileObject('/tmp/phpc_sfo_foreach_aot.txt');
foreach ($f as $line) {
    echo trim($line), ',';
}
echo "\n";
--EXPECT--
a,b,,
