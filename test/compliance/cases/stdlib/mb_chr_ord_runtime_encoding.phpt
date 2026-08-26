--TEST--
mb_chr()/mb_ord() runtime encoding (#34870)
--FILE--
<?php
$e = 'UTF-'.'8';
echo 'chr=', mb_chr(0x3042, $e), "\n";
echo 'ord=', mb_ord('あ', $e), "\n";
try {
    $bad = 'NO_SUCH_ENCODING';
    echo mb_chr(65, $bad);
    echo "no error\n";
} catch (ValueError $err) {
    echo 'err=', $err->getMessage(), "\n";
}
--EXPECT--
chr=あ
ord=12354
err=mb_chr(): Argument #2 ($encoding) must be a valid encoding, "NO_SUCH_ENCODING" given
