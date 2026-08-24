--TEST--
AOT simplexml_load_file with compile-time path (#34454)
--FILE--
<?php
$x = simplexml_load_file('test/repro/fixtures/simplexml_load_file_aot_34454.xml');
echo $x->getName(), ':', (string) $x['a'], "\n";
echo "DONE\n";
--EXPECT--
r:1
DONE
