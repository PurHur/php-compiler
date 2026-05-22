--TEST--
Web: Location redirect sets 302 via http_response_code() (issue #634, tracks #68)
--FILE--
<?php
header('Location: /done');
http_response_code(302);
echo http_response_code(), "\n";
--EXPECT--
302
