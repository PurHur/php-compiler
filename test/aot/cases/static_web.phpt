--TEST--
AOT: minimal HTML response (header + echo, no superglobals)
--FILE--
<?php
$name = 'World';
header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><body>';
echo '<h1>Hello ', $name, "</h1>\n";
echo '</body></html>';
--EXPECT--
Content-Type: text/html; charset=UTF-8
<!DOCTYPE html><html><body><h1>Hello World</h1>
</body></html>
