--TEST--
socket_atmark() — registered on VM under PHP 8.3+ profile (issue #6544, #25874)
--ENV--
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsSocketAtmark()) {
    die('skip socket_atmark requires PHP 8.3+ profile');
}
?>
--FILE--
<?php
declare(strict_types=1);
echo (int) function_exists('socket_atmark'), "\n";
if (!function_exists('stream_socket_pair') || !function_exists('socket_import_stream') || !function_exists('socket_send')) {
    echo "deps_skip\n";
    exit(0);
}
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if (false === $pair) {
    echo "pair_fail\n";
    exit(0);
}
[$a, $b] = $pair;
$sockA = socket_import_stream($a);
$sockB = socket_import_stream($b);
if (false === $sockA || false === $sockB) {
    echo "import_fail\n";
    exit(0);
}
socket_send($sockA, "!", 1, MSG_OOB);
var_export(socket_atmark($sockB));
echo "\n";
?>
--EXPECT--
1
true
