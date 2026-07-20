--TEST--
stdlib htmlspecialchars()/htmlentities() null — DEP+coerce on 8.4 forward profile (#21405, ext/standard/html.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    echo "DEP:{$msg}\n";
    return true;
});
$r1 = htmlspecialchars(null);
echo "htmlspecialchars:[{$r1}]\n";
$r2 = htmlentities(null);
echo "htmlentities:[{$r2}]\n";
?>
--EXPECT--
DEP:htmlspecialchars(): Passing null to parameter #1 ($string) of type string is deprecated
htmlspecialchars:[]
DEP:htmlentities(): Passing null to parameter #1 ($string) of type string is deprecated
htmlentities:[]
