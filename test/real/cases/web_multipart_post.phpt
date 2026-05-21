--TEST--
Web: multipart/form-data POST populates $_POST (issue #52)
--ENV--
CONTENT_TYPE=multipart/form-data; boundary=phpcBoundary
--POST_EXTERNAL--
web_multipart_post.body
--FILE--
<?php
echo $_POST['name'];
--EXPECT--
Ada
