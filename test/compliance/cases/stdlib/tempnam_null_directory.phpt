--TEST--
stdlib tempnam() null directory uses system temp dir (#14672, ext/standard/file.c)
--FILE--
<?php
$p = tempnam(null, 'phpc');
var_dump(is_string($p));
if (is_string($p)) {
    @unlink($p);
}
?>
--EXPECT--
bool(true)
