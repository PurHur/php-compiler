--TEST--
stdlib explode() null separator: deprecate then ValueError empty (#25942, re-#24695)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo 'WARN:', $str, "\n";

    return true;
});
try {
    explode(null, 'a');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    explode('', 'a');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
WARN:explode(): Passing null to parameter #1 ($separator) of type string is deprecated
ValueError:explode(): Argument #1 ($separator) cannot be empty
ValueError:explode(): Argument #1 ($separator) cannot be empty
