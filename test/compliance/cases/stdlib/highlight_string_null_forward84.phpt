--TEST--
stdlib highlight_string(null) — DEP+coerce on 8.4 forward profile (#21504, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(function ($n, $m) {
    if ($n === E_DEPRECATED) {
        echo "DEP\n";
        return true;
    }
    return false;
});
try {
    $r = highlight_string(null, true);
    echo "ok len=" . strlen($r) . "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
DEP
ok len=51
