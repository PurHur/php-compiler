--TEST--
stdlib stream_context_create() options: named parameter (#11114, ext/standard/streams.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

$ctx = stream_context_create(options: ['http' => ['timeout' => 1]]);
var_export(is_resource($ctx));
echo "\n";
?>
--EXPECT--
true
