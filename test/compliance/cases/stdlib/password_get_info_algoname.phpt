--TEST--
stdlib password_get_info() algoName after PASSWORD_BCRYPT hash (#15915, ext/standard/password.c)
--FILE--
<?php
$hash = password_hash('x', PASSWORD_BCRYPT);
$i = password_get_info($hash);
echo ($i['algoName'] ?? 'MISSING') . "\n";
echo var_export($i['algoName'] ?? 'MISSING', true) . "\n";
echo var_export(password_get_info($hash)['algoName'] ?? 'MISSING', true) . "\n";
--EXPECT--
bcrypt
'bcrypt'
'bcrypt'
