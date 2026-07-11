--TEST--
stdlib php://memory one-shot file_put_contents/file_get_contents (#10264)
--FILE--
<?php
declare(strict_types=1);

var_dump(file_put_contents('php://memory', 'hi'));
var_dump(file_get_contents('php://memory'));
var_dump(file_get_contents('php://temp'));
--EXPECT--
int(2)
string(0) ""
string(0) ""
