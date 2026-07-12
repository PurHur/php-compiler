<?php
$arr = ['key' => 'bcrypt'];
echo $arr['key'] . "\n";
echo var_export($arr['key'] ?? 'default', true) . "\n";
