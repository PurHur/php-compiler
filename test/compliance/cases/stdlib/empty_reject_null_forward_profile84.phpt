--TEST--
stdlib empty-rejectable builtins null — ValueError / TypeError on 8.4 forward profile (#18659, #21003)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    ['parse_ini_file', static fn () => parse_ini_file(null)],
    ['checkdnsrr', static fn () => checkdnsrr(null)],
    ['setcookie', static fn () => setcookie(null, 'v')],
] as [$label, $call]) {
    try {
        $call();
        echo "$label: NO_ERROR\n";
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    } catch (TypeError $e) {
        echo "TypeError: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
parse_ini_file(): Argument #1 ($filename) must not be empty
checkdnsrr(): Argument #1 ($hostname) cannot be empty
setcookie(): Argument #1 ($name) must not be empty
