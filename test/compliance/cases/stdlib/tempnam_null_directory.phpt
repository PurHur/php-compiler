--TEST--
stdlib tempnam() null directory uses system temp dir (#14672, #21595, ext/standard/file.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$p = tempnam(null, 'phpc');
var_dump(is_string($p));
if (is_string($p)) {
    @unlink($p);
}
?>
--EXPECT--
bool(true)
