--TEST--
stdlib http_response_code() returns int not ResponseCode enum (#9306, ext/standard/head.c)
--FILE--
<?php
var_dump(http_response_code(404));
var_dump(http_response_code());
var_dump(is_int(http_response_code()));
http_response_code(404);
var_dump(http_response_code(0));
--EXPECT--
bool(true)
int(404)
bool(true)
int(404)
