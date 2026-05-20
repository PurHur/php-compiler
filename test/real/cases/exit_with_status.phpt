--TEST--
Web: exit() after http_response_code() leaves status set
--FILE--
<?php
http_response_code(404);
exit(0);
echo "never\n";
--EXPECT--

