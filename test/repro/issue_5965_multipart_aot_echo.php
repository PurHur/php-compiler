<?php
// Minimal AOT multipart request_parse_body repro (#5965) — no var_export.
$boundary = '----phpc-boundary';
putenv('CONTENT_TYPE=multipart/form-data; boundary=' . $boundary);
$body =
    '--' . $boundary . "\r\n" .
    "Content-Disposition: form-data; name=\"a\"\r\n\r\n" .
    "hi\r\n" .
    '--' . $boundary . "\r\n" .
    "Content-Disposition: form-data; name=\"up\"; filename=\"t.txt\"\r\n" .
    "Content-Type: text/plain\r\n\r\n" .
    "payload\r\n" .
    '--' . $boundary . "--\r\n";
putenv('REQUEST_BODY=' . $body);
$pair = request_parse_body();
echo $pair[0]['a'], "\n";
echo $pair[1]['up']['name'], "\n";
echo $pair[1]['up']['type'], "\n";
echo $pair[1]['up']['error'], "\n";
echo $pair[1]['up']['size'], "\n";
echo file_get_contents($pair[1]['up']['tmp_name']), "\n";
