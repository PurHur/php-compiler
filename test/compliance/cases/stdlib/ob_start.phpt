--TEST--
stdlib ob_start / ob_get_clean / ob_end_flush (issue #118)
--FILE--
<?php
ob_start();
echo 'body';
$b = ob_get_clean();
echo 'wrap:', $b, "\n";
ob_start();
echo 'inner';
echo ' ', ob_get_level(), "\n";
ob_end_flush();
echo 'done';
--EXPECT--
wrap:body
inner 1
done
