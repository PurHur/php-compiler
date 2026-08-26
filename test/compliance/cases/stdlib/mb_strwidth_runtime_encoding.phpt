--TEST--
mb_strwidth/mb_strimwidth runtime encoding (NestedJIT assert; #34884)
--FILE--
<?php
function enc(): string { return 'UTF-8'; }
echo mb_strwidth('über', enc()), "\n";
echo mb_strimwidth('übercafé', 0, 5, '...', enc()), "\n";
try {
    $bad = 'NO_SUCH_ENCODING';
    echo mb_strwidth('x', $bad);
    echo "no error\n";
} catch (ValueError $e) {
    echo "err\n";
}
?>
--EXPECT--
4
üb...
err
