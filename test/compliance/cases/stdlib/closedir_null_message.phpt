--TEST--
stdlib closedir(null) TypeError message — No resource supplied (#14903, ext/standard/dir.c)
--FILE--
<?php
try {
    closedir(null);
    echo "fail\n";
} catch (\TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
No resource supplied
