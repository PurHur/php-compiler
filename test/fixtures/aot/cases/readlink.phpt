--TEST--
AOT: readlink/linkinfo missing-path failure (#28425)
--FILE--
<?php
$missing = @readlink('/no/such/phpc-readlink-path');
echo 'readlink_missing=', (false === $missing) ? 'false' : gettype($missing), "\n";
$dev = @linkinfo('/no/such/phpc-linkinfo-path');
echo 'linkinfo_missing=', (-1 === $dev) ? '-1' : gettype($dev), "\n";
?>
--EXPECT--
readlink_missing=false
linkinfo_missing=-1
