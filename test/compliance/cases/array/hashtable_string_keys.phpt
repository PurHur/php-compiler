--TEST--
array: string-key isset/unset on assoc hashtable (#66)
--FILE--
<?php
$config = ['db' => 'localhost', 'port' => '3306'];
echo $config['db'], "\n";
unset($config['port']);
echo isset($config['db']) ? '1' : '0', "\n";
echo isset($config['port']) ? '1' : '0', "\n";
--EXPECT--
localhost
1
0
