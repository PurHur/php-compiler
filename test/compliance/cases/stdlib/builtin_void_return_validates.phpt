--TEST--
stdlib: void-context builtin calls still validate arguments (#5896, #5900, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

try {
    strip_tags([]);
    echo "strip_tags_after\n";
} catch (TypeError $e) {
    echo "strip_tags: ", $e->getMessage(), "\n";
}

$fp = fopen('php://memory', 'r+');
try {
    fputcsv($fp, [new stdClass()]);
    echo "fputcsv_after\n";
} catch (Error $e) {
    echo "fputcsv: ", $e->getMessage(), "\n";
}
fclose($fp);

try {
    md5([]);
    echo "md5_after\n";
} catch (TypeError $e) {
    echo "md5: ", $e->getMessage(), "\n";
}

try {
    strtr('abc', 1);
    echo "strtr_after\n";
} catch (TypeError $e) {
    echo "strtr: ", $e->getMessage(), "\n";
}

try {
    array_merge('not-array');
    echo "array_merge_after\n";
} catch (TypeError $e) {
    echo "array_merge: ", $e->getMessage(), "\n";
}

try {
    memory_get_usage(true, false);
    echo "memory_get_usage_after\n";
} catch (ArgumentCountError $e) {
    echo "memory_get_usage: ", $e->getMessage(), "\n";
}
?>
--EXPECT--
strip_tags: strip_tags(): Argument #1 ($string) must be of type string, array given
fputcsv: Object of class stdClass could not be converted to string
md5: md5(): Argument #1 ($string) must be of type string, array given
strtr: strtr(): Argument #2 ($from) must be of type array|string, int given
array_merge: array_merge(): Argument #1 must be of type array, string given
memory_get_usage: memory_get_usage() expects at most 1 argument, 2 given
