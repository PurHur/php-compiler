--TEST--
AOT: setrawcookie() emits Set-Cookie CGI header line (issue #1368)
--FILE--
<?php
setrawcookie('sid', 'x');
--EXPECT--
