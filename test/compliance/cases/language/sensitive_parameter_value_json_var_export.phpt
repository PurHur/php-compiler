--TEST--
Language: SensitiveParameterValue json_encode/var_export hide secret (#23042, Zend/zend_exceptions.c)
--FILE--
<?php
function f(#[\SensitiveParameter] string $password, string $user) {
    return debug_backtrace();
}
$sp = f('secret', 'bob')[0]['args'][0];
echo 'json=', json_encode($sp), "\n";
$export = str_replace("\n", ' ', var_export($sp, true));
echo 'export_has_secret=', (str_contains($export, 'secret') ? 'yes' : 'no'), "\n";
echo 'set_state_empty=', (str_contains($export, 'array( )') || str_contains($export, 'array()') ? 'yes' : 'no'), "\n";
echo 'getvalue=', $sp->getValue(), "\n";
ob_start();
var_dump($sp);
$dump = ob_get_clean();
echo 'vardump_has_secret=', (str_contains($dump, 'secret') ? 'yes' : 'no'), "\n";
--EXPECT--
json={}
export_has_secret=no
set_state_empty=yes
getvalue=secret
vardump_has_secret=no
