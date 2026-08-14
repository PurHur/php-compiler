--TEST--
compact() Warning actual false|true not bool (#30119)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $errno, string $message): bool {
    echo $message, "\n";

    return true;
});
foreach ([false, true] as $v) {
    $r = compact($v);
    var_dump($r);
}
?>
--EXPECT--
compact(): Argument #1 must be string or array of strings, false given
array(0) {
}
compact(): Argument #1 must be string or array of strings, true given
array(0) {
}
