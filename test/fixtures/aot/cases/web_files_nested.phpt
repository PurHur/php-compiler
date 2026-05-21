--TEST--
AOT: nested $_FILES field access after multipart upload (issue #87)
--ENV--
REQUEST_METHOD=POST
CONTENT_TYPE=multipart/form-data; boundary=phpcAotFileB
--POST--
--phpcAotFileB
Content-Disposition: form-data; name="doc"; filename="f.txt"
Content-Type: text/plain

bytes
--phpcAotFileB--
--FILE--
<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo $_FILES['doc']['name'];
--EXPECTF--
Content-Type: text/plain; charset=UTF-8
f.txt
--EXPECT_EXIT--
0
