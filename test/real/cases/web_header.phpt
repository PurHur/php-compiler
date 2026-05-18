--TEST--
Web: header() before HTML body output
--FILE--
<?php
header('Content-Type: text/html; charset=UTF-8');
echo '<p>ok</p>', "\n";
--EXPECT--
<p>ok</p>

