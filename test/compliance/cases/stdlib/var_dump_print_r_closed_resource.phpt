--TEST--
stdlib var_dump()/print_r() on closed stream — resource(id) not object(Resource) (#5149)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fclose($h);
var_dump($h);
echo "---\n";
echo print_r($h, true), "\n";
--EXPECTREGEX--
^resource\(\d+\) of type \(Unknown\)\n---\nResource id #\d+$
