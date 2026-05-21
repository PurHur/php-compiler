--TEST--
Web: read cookies from $_COOKIE (issue #271)
--COOKIE--
session=abc123; theme=dark
--FILE--
<?php
echo $_COOKIE['session'], '|', $_COOKIE['theme'], "\n";
--EXPECT--
abc123|dark
