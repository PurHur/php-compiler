<?php
$data = str_repeat('abc', 100);
$c = gzcompress($data, 6);
var_dump(is_string($c), strlen($c) < strlen($data));
var_dump(gzinflate(substr($c, 2, -4)) === $data);
