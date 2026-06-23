--TEST--
AOT getprotobyname()/getservbyname() network lookups (#4024)
--SKIPIF--
<?php
if (!is_readable('/etc/protocols')) {
    echo "skip no protocols database\n";
}
if (!is_readable('/etc/services')) {
    echo "skip no services database\n";
}
?>
--FILE--
<?php
$proto = getprotobyname('tcp');
$port = getservbyname('http', 'tcp');
echo is_int($proto) ? "proto_int\n" : "proto_not_int\n";
echo is_int($port) ? "port_int\n" : "port_not_int\n";

// Negative cases should return false (Zend also warns; stderr not asserted here).
$badProto = getprotobyname('__phpc_missing_proto__');
$badSvc = getservbyname('__phpc_missing_service__', 'tcp');
echo (is_int($badProto) && 0 === $badProto) ? "bad_proto_0\n" : "bad_proto_not_0\n";
echo (is_int($badSvc) && 0 === $badSvc) ? "bad_svc_0\n" : "bad_svc_not_0\n";
--EXPECTF--
proto_int
port_int
bad_proto_0
bad_svc_0

