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
