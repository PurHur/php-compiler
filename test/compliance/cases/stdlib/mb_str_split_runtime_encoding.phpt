--TEST--
mb_str_split runtime encoding (AOT NestedJIT #34880)
--FILE--
<?php
$e = 'UTF-' . '8';
echo implode(',', mb_str_split('あいう', 1, $e)), "\n";
try {
    mb_str_split('a', 1, 'NOPE');
    echo "no error\n";
} catch (ValueError $err) {
    echo $err->getMessage(), "\n";
}
?>
--EXPECT--
あ,い,う
mb_str_split(): Argument #3 ($encoding) must be a valid encoding, "NOPE" given
