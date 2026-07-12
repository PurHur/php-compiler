--TEST--
Language: echo var_export with ?? array offset and concat suffix (#18315)
--FILE--
<?php
$arr = ['key' => 'bcrypt'];
echo $arr['key'] . "\n";
echo var_export($arr['key'] ?? 'default', true) . "\n";
echo var_export($arr['key'] ?? 'default', true), "\n";
--EXPECT--
bcrypt
'bcrypt'
'bcrypt'
