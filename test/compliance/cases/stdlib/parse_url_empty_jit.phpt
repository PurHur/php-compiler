--TEST--
stdlib parse_url('') empty URL JIT/AOT (#10301)
--FILE--
<?php
$empty = parse_url('');
echo count($empty), "\n";
echo array_key_exists('path', $empty) ? 'yes' : 'no', "\n";
echo $empty['path'], "\n";
echo var_export(parse_url('', PHP_URL_PATH), true), "\n";
$queryOnly = parse_url('?q=1');
echo array_key_exists('path', $queryOnly) ? 'yes' : 'no', "\n";
echo $queryOnly['query'], "\n";
--EXPECT--
1
yes

''
no
q=1
