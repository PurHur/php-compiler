--TEST--
AOT request_parse_body multipart parsing (PHP 8.4 profile, #16927)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Literal putenv — Nested JIT putenv+concat setenv still drops REQUEST_BODY (#5965).
// Prefer $pair[0]/[1] over [$post,$files]= — list-destructure AOT aborts (#5965).
putenv('CONTENT_TYPE=multipart/form-data; boundary=----phpc-boundary');
putenv("REQUEST_BODY=------phpc-boundary\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nhi\r\n------phpc-boundary\r\nContent-Disposition: form-data; name=\"up\"; filename=\"t.txt\"\r\nContent-Type: text/plain\r\n\r\npayload\r\n------phpc-boundary--\r\n");
$pair = request_parse_body();
echo $pair[0]['a'], "\n";
echo $pair[1]['up']['name'], "\n";
echo $pair[1]['up']['type'], "\n";
echo $pair[1]['up']['error'], "\n";
echo $pair[1]['up']['size'], "\n";
echo file_get_contents($pair[1]['up']['tmp_name']), "\n";
--EXPECT--
hi
t.txt
text/plain
0
7
payload
