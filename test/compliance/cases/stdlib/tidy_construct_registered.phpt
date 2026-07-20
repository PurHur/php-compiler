--TEST--
tidy::__construct registered (#21603)
--FILE--
<?php
echo (int) method_exists('tidy', '__construct'), "\n";
$t = new tidy();
echo get_class($t), "\n";
echo (int) ($t instanceof tidy), "\n";
try {
    new tidy(__DIR__.'/no_such_tidy_file_21603.html');
    echo "missing_file=ok_unexpected\n";
} catch (Throwable $e) {
    echo 'missing_file=', get_class($e), "\n";
}
?>
--EXPECT--
1
tidy
1
missing_file=Error
