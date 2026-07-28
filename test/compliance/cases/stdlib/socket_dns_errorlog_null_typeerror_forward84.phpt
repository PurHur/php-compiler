--TEST--
stdlib error_log/gethostbyname/dns_get_record(null) soft-null + pfsockopen(null) TypeError (#24178 / #23823)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (
    [
        'error_log' => static fn () => error_log(null),
        'pfsockopen' => static fn () => pfsockopen(null, 80),
        'gethostbyname' => static fn () => gethostbyname(null),
        'dns_get_record' => static fn () => dns_get_record(null),
    ] as $fn => $call
) {
    try {
        $call();
        echo $fn, " COERCED\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
error_log COERCED
pfsockopen: pfsockopen(): Argument #1 ($hostname) must be of type string, null given
gethostbyname COERCED
dns_get_record COERCED
