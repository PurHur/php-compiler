<?php
$a = preg_split('/\s+/', 'a  b   c');
echo 'count=', count($a), PHP_EOL;
foreach ($a as $k => $v) {
    echo $k, '=', gettype($v), ':', (string) $v, PHP_EOL;
}
