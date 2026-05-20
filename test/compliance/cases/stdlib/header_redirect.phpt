--TEST--
stdlib header() Location redirect with response code (issue #122)
--FILE--
<?php
header('Location: /thanks', true, 302);
echo http_response_code(), "\n";
--EXPECT--
302
