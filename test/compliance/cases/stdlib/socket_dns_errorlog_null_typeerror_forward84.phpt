--TEST--
stdlib error_log/pfsockopen/gethostbyname/dns_get_record(null) TypeError batch (#23858, reverts #21446)
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
error_log: error_log(): Argument #1 ($message) must be of type string, null given
pfsockopen: pfsockopen(): Argument #1 ($hostname) must be of type string, null given
gethostbyname: gethostbyname(): Argument #1 ($hostname) must be of type string, null given
dns_get_record: dns_get_record(): Argument #1 ($hostname) must be of type string, null given
