--TEST--
AOT: ArrayObject then echo (cond ? lit : lit) . "\n" (#33094 / #18784)
--FILE--
<?php
declare(strict_types=1);
$o = new ArrayObject(['z' => 0]);
echo (true ? 'true' : 'false') . "\n";
echo (isset($o['z']) ? 'true' : 'false') . "\n";
--EXPECT--
true
true
