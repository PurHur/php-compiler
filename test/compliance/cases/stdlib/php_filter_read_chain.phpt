--TEST--
stdlib file_get_contents() php://filter read chains (issue #13741)
--FILE--
<?php
echo file_get_contents('php://filter/read=string.toupper/resource=data://text/plain,hello'), "\n";
echo file_get_contents('php://filter/read=string.rot13/resource=data://text/plain,uryyb'), "\n";
echo file_get_contents('php://filter/read=convert.quoted-printable-decode/resource=data://text/plain,=48=65=6C=6C=6F'), "\n";
echo file_get_contents('php://filter/read=string.tolower|string.toupper/resource=data://text/plain,hello'), "\n";
--EXPECT--
HELLO
hello
Hello
HELLO
