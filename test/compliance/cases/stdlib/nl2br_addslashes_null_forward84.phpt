--TEST--
stdlib nl2br()/addslashes() null — DEP+coerce on 8.4 forward profile (#21406, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    echo "DEP:{$msg}\n";
    return true;
});
$r1 = nl2br(null);
echo "nl2br:[{$r1}]\n";
$r2 = addslashes(null);
echo "addslashes:[{$r2}]\n";
?>
--EXPECT--
DEP:nl2br(): Passing null to parameter #1 ($string) of type string is deprecated
nl2br:[]
DEP:addslashes(): Passing null to parameter #1 ($string) of type string is deprecated
addslashes:[]
