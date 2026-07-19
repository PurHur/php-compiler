<?php
/** Issue #6383 — mailparse_msg_create/parse/get_part_data + rfc822 addresses. */
foreach (['mailparse_msg_create', 'mailparse_msg_parse', 'mailparse_rfc822_parse_addresses'] as $f) {
    echo $f, ': ', function_exists($f) ? 'yes' : 'no', "\n";
}

$msg = mailparse_msg_create();
mailparse_msg_parse($msg, "From: a@b.c\r\nSubject: hi\r\n\r\nbody");
$headers = mailparse_msg_get_part_data($msg);
echo $headers['headers']['subject'] ?? 'MISSING', "\n";

$addrs = mailparse_rfc822_parse_addresses('Wez Furlong <wez@example.com>, doe@example.com');
echo $addrs[0]['address'] ?? 'MISSING', "\n";
echo $addrs[1]['display'] ?? 'MISSING', "\n";
