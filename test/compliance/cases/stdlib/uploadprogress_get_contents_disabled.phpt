--TEST--
stdlib uploadprogress_get_contents() — disabled by default (#6386, ext/uploadprogress)
--FILE--
<?php
declare(strict_types=1);

$result = @uploadprogress_get_contents('missing-id', 'file');
echo ($result === false ? "disabled\n" : "unexpected\n");
--EXPECT--
disabled
