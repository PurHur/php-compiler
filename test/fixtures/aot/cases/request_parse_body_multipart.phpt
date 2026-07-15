--TEST--
AOT request_parse_body multipart parsing (PHP 8.4 profile, #16927)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Literal putenv — Nested JIT putenv+concat setenv still drops REQUEST_BODY (#5965).
putenv('CONTENT_TYPE=multipart/form-data; boundary=----phpc-boundary');
putenv("REQUEST_BODY=------phpc-boundary\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nhi\r\n------phpc-boundary\r\nContent-Disposition: form-data; name=\"up\"; filename=\"t.txt\"\r\nContent-Type: text/plain\r\n\r\npayload\r\n------phpc-boundary--\r\n");
[$post, $files] = request_parse_body();
echo $post['a'], "\n";
echo $files['up']['name'], "\n";
echo $files['up']['type'], "\n";
echo $files['up']['error'], "\n";
echo $files['up']['size'], "\n";
echo file_get_contents($files['up']['tmp_name']), "\n";
?>
--EXPECT--
hi
t.txt
text/plain
0
7
payload
