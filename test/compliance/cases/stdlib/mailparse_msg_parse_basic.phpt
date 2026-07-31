--TEST--
ext/mailparse Phase 1 — msg create/parse/get_part_data + rfc822 (#6383)
--ENV--
PHP_COMPILER_ENABLE_MAILPARSE=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\mailparse\MailparseExtensionPolicy::advertisesExtension()) {
    die('skip mailparse withheld (#24908)');
}
?>
--FILE--
<?php
foreach (['mailparse_msg_create', 'mailparse_msg_parse', 'mailparse_msg_get_part_data', 'mailparse_rfc822_parse_addresses', 'mailparse_msg_free'] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
echo 'extension_loaded=', var_export(extension_loaded('mailparse'), true), "\n";

$msg = mailparse_msg_create();
mailparse_msg_parse($msg, '');
$data = mailparse_msg_get_part_data($msg);
echo 'empty_headers=', var_export($data['headers'] === [], true), "\n";

mailparse_msg_parse($msg, "From: a@b.c\r\nSubject: hi\r\n\r\nbody");
$data = mailparse_msg_get_part_data($msg);
echo 'subject=', $data['headers']['subject'] ?? 'MISSING', "\n";
echo 'from=', $data['headers']['from'] ?? 'MISSING', "\n";

try {
    mailparse_msg_parse(42, 'x');
    echo "int_ok\n";
} catch (TypeError $e) {
    echo "int_typeerror\n";
}

enum E6383: string { case A = 'x'; }
try {
    mailparse_msg_parse($msg, E6383::A);
    echo "enum_ok\n";
} catch (TypeError $e) {
    echo "enum_typeerror\n";
}

$addrs = mailparse_rfc822_parse_addresses('Wez Furlong <wez@example.com>, doe@example.com');
echo 'addr0=', $addrs[0]['address'], "\n";
echo 'disp1=', $addrs[1]['display'], "\n";
mailparse_msg_free($msg);
?>
--EXPECT--
mailparse_msg_create=true
mailparse_msg_parse=true
mailparse_msg_get_part_data=true
mailparse_rfc822_parse_addresses=true
mailparse_msg_free=true
extension_loaded=true
empty_headers=true
subject=hi
from=a@b.c
int_typeerror
enum_typeerror
addr0=wez@example.com
disp1=doe@example.com
