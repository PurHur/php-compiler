--TEST--
sample: SKIPIF skips when requested
--SKIPIF--
<?php echo "skip intentional harness check\n"; ?>
--FILE--
<?php
echo "should-not-run\n";
?>
--EXPECT--
should-not-run
