--TEST--
AOT: static web page (header, htmlspecialchars, echo)
--FILE--
<?php
$name = 'World';
header('Content-Type: text/html; charset=UTF-8');
echo header_list()[0], "\n";
echo '<!DOCTYPE html><html><body>';
echo '<h1>Hello ', htmlspecialchars($name), "</h1>\n";
echo '</body></html>';
--EXPECTF--
Content-Type: text/html; charset=UTF-8
<!DOCTYPE html><html><body><h1>Hello World</h1>
</body></html>
