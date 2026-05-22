--TEST--
Web: header() Location + http_response_code(302) (issue #634)
--FILE--
<?php
header('Location: /done');
http_response_code(302);
echo http_response_code(), "\n";
--EXPECT--
302
