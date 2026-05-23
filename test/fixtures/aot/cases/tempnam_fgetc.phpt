--TEST--
AOT: tempnam() and fgetc()
--FILE--
<?php
$p = tempnam(sys_get_temp_dir(), 'phpc_aot_tn_');
if (!is_string($p)) {
    echo "notemp\n";
    exit;
}
$fp = fopen('test/compliance/cases/stdlib/fgetc_fixture.txt', 'r');
$c = fgetc($fp);
fclose($fp);
@unlink($p);
if ('H' === $c) {
    echo "ok\n";
} else {
    echo "bad\n";
}
--EXPECT--
ok
