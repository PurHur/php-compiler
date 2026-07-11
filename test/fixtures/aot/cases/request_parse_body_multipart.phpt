--TEST--
AOT request_parse_body multipart parsing (PHP 8.4 profile, #16927)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
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

