--TEST--
mb_strpos/mb_strstr runtime encoding (AOT NestedJIT #34866)
--FILE--
<?php
$e = 'UTF-' . '8';
echo mb_strpos('あい', 'い', 0, $e), "\n";
echo mb_strstr('あいウ', 'い', false, $e), "\n";
try {
    mb_strpos('a', 'b', 0, 'NOPE');
    echo "no error\n";
} catch (ValueError $err) {
    echo $err->getMessage(), "\n";
}
?>
--EXPECT--
1
いウ
mb_strpos(): Argument #4 ($encoding) must be a valid encoding, "NOPE" given
