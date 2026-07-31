--TEST--
ext/mailparse multipart structure/extract/parse_file (#22230)
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
foreach ([
    'mailparse_msg_extract_part',
    'mailparse_msg_get_structure',
    'mailparse_msg_get_part',
    'mailparse_msg_parse_file',
    'mailparse_stream_encode',
    'mailparse_uudecode_all',
    'mailparse_determine_best_xfer_encoding',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}

$raw = "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b\"\r\n\r\n--b\r\nContent-Type: text/plain\r\n\r\nHi\r\n--b--\r\n";
$msg = mailparse_msg_create();
mailparse_msg_parse($msg, $raw);
$struct = mailparse_msg_get_structure($msg);
echo 'has_root=', var_export(in_array('1', $struct, true), true), "\n";
echo 'has_child=', var_export(in_array('1.1', $struct, true), true), "\n";
$part = mailparse_msg_get_part($msg, '1.1');
$text = mailparse_msg_extract_part($part, $raw, null);
echo 'extract=', var_export(trim($text), true), "\n";

$tmp = sys_get_temp_dir().'/mp22230_'.getmypid().'.eml';
file_put_contents($tmp, $raw);
$fromFile = mailparse_msg_parse_file($tmp);
echo 'parse_file_struct=', implode(',', mailparse_msg_get_structure($fromFile)), "\n";
@unlink($tmp);

$fp = fopen('php://memory', 'r+b');
fwrite($fp, "ok\n");
rewind($fp);
echo 'best=', mailparse_determine_best_xfer_encoding($fp), "\n";
mailparse_msg_free($msg);
?>
--EXPECT--
mailparse_msg_extract_part=true
mailparse_msg_get_structure=true
mailparse_msg_get_part=true
mailparse_msg_parse_file=true
mailparse_stream_encode=true
mailparse_uudecode_all=true
mailparse_determine_best_xfer_encoding=true
has_root=true
has_child=true
extract='Hi'
parse_file_struct=1,1.1
best=7bit
