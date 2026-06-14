--TEST--
stdlib php://filter wrapper — read=string.toupper on php://memory (#4702, php_stream_filter.c)
--FILE--
<?php
$uri = 'php://filter/read=string.toupper/resource=php://memory';
$h = @fopen($uri, 'r+');
var_dump($h !== false);
if (false !== $h) {
    fwrite($h, 'hello');
    rewind($h);
    echo stream_get_contents($h), "\n";
    fclose($h);
}
$wuri = 'php://filter/write=string.toupper/resource=php://memory';
$w = @fopen($wuri, 'w+');
var_dump($w !== false);
if (false !== $w) {
    fwrite($w, 'hi');
    rewind($w);
    echo stream_get_contents($w), "\n";
    fclose($w);
}
?>
--EXPECT--
bool(true)
HELLO
bool(true)
HI
