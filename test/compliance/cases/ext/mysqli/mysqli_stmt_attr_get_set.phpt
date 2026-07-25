--TEST--
ext/mysqli mysqli_stmt_attr_get/attr_set + MYSQLI_STMT_ATTR_* (#22175, php-src mysqli_api.c)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('mysqli_stmt_execute') ? "mysqli_stmt_execute=yes\n" : "mysqli_stmt_execute=no\n";
echo function_exists('mysqli_stmt_attr_get') ? "mysqli_stmt_attr_get=yes\n" : "mysqli_stmt_attr_get=no\n";
echo function_exists('mysqli_stmt_attr_set') ? "mysqli_stmt_attr_set=yes\n" : "mysqli_stmt_attr_set=no\n";
echo defined('MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH') ? 'UPDATE_MAX_LENGTH='.MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH."\n" : "UPDATE_MAX_LENGTH=no\n";
echo defined('MYSQLI_STMT_ATTR_CURSOR_TYPE') ? 'CURSOR_TYPE='.MYSQLI_STMT_ATTR_CURSOR_TYPE."\n" : "CURSOR_TYPE=no\n";
echo defined('MYSQLI_STMT_ATTR_PREFETCH_ROWS') ? 'PREFETCH_ROWS='.MYSQLI_STMT_ATTR_PREFETCH_ROWS."\n" : "PREFETCH_ROWS=no\n";
echo defined('MYSQLI_CURSOR_TYPE_NO_CURSOR') ? 'NO_CURSOR='.MYSQLI_CURSOR_TYPE_NO_CURSOR."\n" : "NO_CURSOR=no\n";
echo defined('MYSQLI_CURSOR_TYPE_READ_ONLY') ? 'READ_ONLY='.MYSQLI_CURSOR_TYPE_READ_ONLY."\n" : "READ_ONLY=no\n";
echo class_exists('mysqli_stmt') ? "mysqli_stmt=yes\n" : "mysqli_stmt=no\n";

try {
    mysqli_stmt_attr_get();
    echo "arity_get=no\n";
} catch (ArgumentCountError $e) {
    echo "arity_get=yes\n";
}
try {
    mysqli_stmt_attr_set();
    echo "arity_set=no\n";
} catch (ArgumentCountError $e) {
    echo "arity_set=yes\n";
}
try {
    mysqli_stmt_attr_get(false, 0);
    echo "type_get=no\n";
} catch (TypeError $e) {
    echo "type_get=yes\n";
}
?>
--EXPECT--
mysqli_stmt_execute=yes
mysqli_stmt_attr_get=yes
mysqli_stmt_attr_set=yes
UPDATE_MAX_LENGTH=0
CURSOR_TYPE=1
PREFETCH_ROWS=2
NO_CURSOR=0
READ_ONLY=1
mysqli_stmt=yes
arity_get=yes
arity_set=yes
type_get=yes
