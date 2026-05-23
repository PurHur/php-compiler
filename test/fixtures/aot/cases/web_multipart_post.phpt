--TEST--
AOT: multipart/form-data POST populates $_POST (issue #52)
--ENV--
REQUEST_METHOD=POST
CONTENT_TYPE=multipart/form-data; boundary=phpcBoundary
--POST--
--phpcBoundary
Content-Disposition: form-data; name="name"

Ada
--phpcBoundary--
--FILE--
<?php
echo $_POST['name'];
--EXPECT--
Ada
--EXPECT_EXIT--
0
