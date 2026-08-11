--TEST--
stdlib DNS hostname builtins JIT — backed enum case TypeError (#6264)
--FILE--
<?php
enum E: string { case A = 'example.com'; }

try {
    gethostbynamel(E::A);
    echo "uncaught gethostbynamel\n";
} catch (TypeError $e) {
    echo 'gethostbynamel: ', $e->getMessage(), "\n";
}
try {
    gethostbyname(E::A);
    echo "uncaught gethostbyname\n";
} catch (TypeError $e) {
    echo 'gethostbyname: ', $e->getMessage(), "\n";
}
try {
    gethostbyaddr(E::A);
    echo "uncaught gethostbyaddr\n";
} catch (TypeError $e) {
    echo 'gethostbyaddr: ', $e->getMessage(), "\n";
}
try {
    checkdnsrr(E::A);
    echo "uncaught checkdnsrr\n";
} catch (TypeError $e) {
    echo 'checkdnsrr: ', $e->getMessage(), "\n";
}
try {
    dns_get_record(E::A);
    echo "uncaught dns_get_record\n";
} catch (TypeError $e) {
    echo 'dns_get_record: ', $e->getMessage(), "\n";
}
try {
    $mx = [];
    $weights = [];
    getmxrr(E::A, $mx, $weights);
    echo "uncaught getmxrr\n";
} catch (TypeError $e) {
    echo 'getmxrr: ', $e->getMessage(), "\n";
}
--EXPECT--
gethostbynamel: gethostbynamel(): Argument #1 ($hostname) must be of type string, E given
gethostbyname: gethostbyname(): Argument #1 ($hostname) must be of type string, E given
gethostbyaddr: gethostbyaddr(): Argument #1 ($ip) must be of type string, E given
checkdnsrr: checkdnsrr(): Argument #1 ($hostname) must be of type string, E given
dns_get_record: dns_get_record(): Argument #1 ($hostname) must be of type string, E given
getmxrr: getmxrr(): Argument #1 ($hostname) must be of type string, E given
