--TEST--
stdlib header() — named replace/response_code compile and run (#9104, head.c)
--FILE--
<?php
header('X-Test: 1', replace: true);
header('X-Other: 2', replace: false);
header('X-Status: 3', response_code: 418);
echo "ok\n";
--EXPECT--
ok
