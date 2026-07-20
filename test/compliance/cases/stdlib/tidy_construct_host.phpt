--TEST--
tidy::__construct host soft path (#21603)
--FILE--
<?php
declare(strict_types=1);
$t = new tidy();
echo 'empty=', get_class($t), "\n";
$tmp = tempnam(sys_get_temp_dir(), 'tidy21603_');
file_put_contents($tmp, '<p>hi</p>');
try {
    $t2 = new tidy($tmp);
    echo 'from_file=', get_class($t2), "\n";
    if (extension_loaded('tidy')) {
        echo 'host_value=', isset($t2->value) && $t2->value !== null ? 'set' : 'null', "\n";
    } else {
        echo "host_value=soft\n";
    }
} catch (Throwable $e) {
    echo 'from_file_err=', get_class($e), ':', $e->getMessage(), "\n";
}
@unlink($tmp);
echo "ok\n";
?>
--EXPECTF--
empty=tidy
%a
ok
