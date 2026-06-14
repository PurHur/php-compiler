--TEST--
stdlib InfoView enum for phpinfo() (#7285, ext/standard/info.c)
--FILE--
<?php
var_export(function_exists('phpinfo'));
echo "\n";
var_export(enum_exists('InfoView', false));
echo "\n";
var_export(InfoView::General->name);
echo "\n";
var_export(InfoView::General->value);
echo "\n";
var_export(InfoView::All->value);
echo "\n";
ob_start();
phpinfo(InfoView::General);
$out = ob_get_clean();
echo str_contains($out, 'PHP Version') ? "general_ok\n" : "general_missing\n";
ob_start();
phpinfo(INFO_GENERAL);
$outInt = ob_get_clean();
echo str_contains($outInt, 'PHP Version') ? "int_ok\n" : "int_missing\n";
enum Es: int { case B = 1; }
try {
    phpinfo(Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
'General'
1
-1
general_ok
int_ok
phpinfo(): Argument #1 ($flags) must be of type InfoView|int|null, Es given
