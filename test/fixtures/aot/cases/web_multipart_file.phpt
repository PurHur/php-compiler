--TEST--
AOT: multipart file upload populates $_FILES (#878)
--ENV--
REQUEST_METHOD=POST
CONTENT_TYPE=multipart/form-data; boundary=phpcFileB
--POST--
--phpcFileB
Content-Disposition: form-data; name="doc"; filename="README.md"
Content-Type: text/plain

bytes
--phpcFileB--
--FILE--
<?php
echo $_FILES['doc']['name'];
--EXPECT--
README.md
--EXPECT_EXIT--
0
