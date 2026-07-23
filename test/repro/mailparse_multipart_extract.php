<?php
// Repro #22230 — mailparse multipart structure / get_part / extract / parse_file + helpers
$fns = [
    'mailparse_msg_create',
    'mailparse_msg_parse',
    'mailparse_msg_extract_part',
    'mailparse_msg_extract_part_file',
    'mailparse_msg_extract_whole_part_file',
    'mailparse_msg_get_structure',
    'mailparse_msg_get_part',
    'mailparse_msg_parse_file',
    'mailparse_stream_encode',
    'mailparse_uudecode_all',
    'mailparse_determine_best_xfer_encoding',
];
foreach ($fns as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}

$raw = "From: a@b.c\r\n".
    "MIME-Version: 1.0\r\n".
    "Content-Type: multipart/mixed; boundary=\"bound1\"\r\n".
    "\r\n".
    "preamble\r\n".
    "--bound1\r\n".
    "Content-Type: text/plain; charset=utf-8\r\n".
    "Content-Transfer-Encoding: 7bit\r\n".
    "\r\n".
    "Hello MIME\r\n".
    "--bound1--\r\n";

$msg = mailparse_msg_create();
mailparse_msg_parse($msg, $raw);
$struct = mailparse_msg_get_structure($msg);
echo 'struct=', implode(',', $struct), "\n";

$part = mailparse_msg_get_part($msg, '1.1');
echo 'part=', $part ? 'ok' : 'missing', "\n";
$body = mailparse_msg_extract_part($part, $raw, null);
echo 'body=', var_export($body, true), "\n";

$tmp = sys_get_temp_dir().'/mailparse22230_'.getmypid().'.eml';
file_put_contents($tmp, $raw);
$msg2 = mailparse_msg_parse_file($tmp);
echo 'parse_file=', is_object($msg2) || is_resource($msg2) ? 'ok' : 'fail', "\n";
$struct2 = mailparse_msg_get_structure($msg2);
echo 'struct2=', implode(',', $struct2), "\n";
@unlink($tmp);

$fp = fopen('php://memory', 'r+b');
fwrite($fp, "short ascii\n");
rewind($fp);
echo 'best=', mailparse_determine_best_xfer_encoding($fp), "\n";

$src = fopen('php://memory', 'r+b');
$dst = fopen('php://memory', 'r+b');
fwrite($src, 'abc');
rewind($src);
echo 'encode=', mailparse_stream_encode($src, $dst, 'base64') ? 'Y' : 'N', "\n";
rewind($dst);
echo 'encoded=', trim(stream_get_contents($dst)), "\n";

$uufp = fopen('php://memory', 'r+b');
fwrite($uufp, "begin 644 hello.txt\n".convert_uuencode("hi")."end\n");
rewind($uufp);
$uu = mailparse_uudecode_all($uufp);
echo 'uue=', is_array($uu) && count($uu) >= 2 ? 'Y' : 'N', "\n";

mailparse_msg_free($msg);
