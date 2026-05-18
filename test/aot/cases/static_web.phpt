--TEST--
AOT: static web page (header + echo; see header_static.phpt)
--FILE--
<?php
header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><body>';
echo '<h1>Hello World</h1>', "\n";
echo '</body></html>';
--EXPECTF--
Content-Type: text/html; charset=UTF-8
<!DOCTYPE html><html><body><h1>Hello World</h1>
</body></html>
