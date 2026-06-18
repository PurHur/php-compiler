--TEST--
stdlib http_response_code() JIT — numeric-string coercion (#4454)
--FILE--
<?php
http_response_code("404");
echo http_response_code(), "\n";
http_response_code(null);
echo http_response_code() === 404 ? "ok\n" : "fail\n";
--EXPECT--
404
ok
