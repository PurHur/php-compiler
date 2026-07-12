--TEST--
stdlib ob_start() closure callback transforms buffer on flush (issue #3623)
--FILE--
<?php
ob_start(fn($b, $p) => strtoupper($b));
echo 'hi';
echo ob_get_clean();
--EXPECT--
hi
