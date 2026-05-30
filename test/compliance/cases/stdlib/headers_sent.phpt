--TEST--
stdlib headers_sent() false before body output (issue #3120)
--FILE--
<?php
echo headers_sent() ? "sent\n" : "not\n";
echo "body\n";
echo headers_sent() ? "sent\n" : "not\n";
--EXPECT--
not
body
sent
--EXPECT_EXIT--
0
