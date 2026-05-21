--TEST--
Web: nested $_FILES keys from multipart POST (issue #87)
--ENV--
REQUEST_METHOD=POST
CONTENT_TYPE=multipart/form-data; boundary=phpcWebFileB
--POST--
--phpcWebFileB
Content-Disposition: form-data; name="doc"; filename="upload.txt"
Content-Type: text/plain

hello
--phpcWebFileB--
--FILE--
<?php
declare(strict_types=1);
echo $_FILES['doc']['name'], "\n";
--EXPECT--
upload.txt
