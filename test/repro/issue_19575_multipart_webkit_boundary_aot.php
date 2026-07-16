<?php
// General rfc1867 boundary AOT (#19628 / #19575) — literal putenv only (no concat).
putenv('CONTENT_TYPE=multipart/form-data; boundary=----WebKitFormBoundary7MA4YWxkTrZu0gW');
putenv("REQUEST_BODY=------WebKitFormBoundary7MA4YWxkTrZu0gW\r\nContent-Disposition: form-data; name=\"user\"\r\n\r\nalice\r\n------WebKitFormBoundary7MA4YWxkTrZu0gW\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"a.png\"\r\nContent-Type: text/plain\r\n\r\npixel\r\n------WebKitFormBoundary7MA4YWxkTrZu0gW--\r\n");
$pair = request_parse_body();
echo $pair[0]['user'], "\n";
echo $pair[1]['avatar']['name'], "\n";
echo $pair[1]['avatar']['type'], "\n";
echo $pair[1]['avatar']['error'], "\n";
echo $pair[1]['avatar']['size'], "\n";
echo file_get_contents($pair[1]['avatar']['tmp_name']), "\n";
