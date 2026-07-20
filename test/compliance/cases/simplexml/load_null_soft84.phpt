--TEST--
SimpleXML: simplexml_load_string/file(null) soft-null DEP+false on 8.4 (#21502, reverts #20352 TypeError)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    // Load-file empty path emits an I/O warning after soft-null coerce.
    if (E_WARNING === $no) {
        echo "WARN:{$msg}\n";
        return true;
    }
    echo "E{$no}:{$msg}\n";
    return true;
});
try {
    $r = simplexml_load_string(null);
    echo 'string:', var_export($r === false, true), "\n";
} catch (Throwable $e) {
    echo 'string_err:', get_class($e), "\n";
}
try {
    $r = simplexml_load_file(null);
    echo 'file:', var_export($r === false, true), "\n";
} catch (Throwable $e) {
    echo 'file_err:', get_class($e), "\n";
}
?>
--EXPECT--
DEP:simplexml_load_string(): Passing null to parameter #1 ($data) of type string is deprecated
string:true
DEP:simplexml_load_file(): Passing null to parameter #1 ($filename) of type string is deprecated
WARN:simplexml_load_file(): failed to load external entity ""
file:true
