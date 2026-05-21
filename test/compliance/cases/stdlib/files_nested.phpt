--TEST--
stdlib nested $_FILES access (issue #87)
--ENV--
REQUEST_METHOD=POST
CONTENT_TYPE=multipart/form-data; boundary=phpcJitFileB
--POST--
--phpcJitFileB
Content-Disposition: form-data; name="doc"; filename="photo.txt"
Content-Type: text/plain

filedata
--phpcJitFileB--
--FILE--
<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo $_FILES['doc']['name'], "\n";
--EXPECT--
photo.txt
