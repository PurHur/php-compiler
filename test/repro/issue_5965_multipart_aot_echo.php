<?php
// Minimal AOT multipart request_parse_body (#5965) — literal putenv (concat setenv still fragile).
putenv('CONTENT_TYPE=multipart/form-data; boundary=----phpc-boundary');
putenv("REQUEST_BODY=------phpc-boundary\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nhi\r\n------phpc-boundary\r\nContent-Disposition: form-data; name=\"up\"; filename=\"t.txt\"\r\nContent-Type: text/plain\r\n\r\npayload\r\n------phpc-boundary--\r\n");
$pair = request_parse_body();
echo $pair[0]['a'], "\n";
echo $pair[1]['up']['name'], "\n";
echo $pair[1]['up']['type'], "\n";
echo $pair[1]['up']['error'], "\n";
echo $pair[1]['up']['size'], "\n";
echo file_get_contents($pair[1]['up']['tmp_name']), "\n";
