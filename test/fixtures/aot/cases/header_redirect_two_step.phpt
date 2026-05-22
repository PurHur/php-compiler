--TEST--
AOT: Location header then http_response_code(302) — Status before Location (issue #634)
--FILE--
<?php
header('Location: /done');
http_response_code(302);
--EXPECT--
Status: 302
Location: /done
