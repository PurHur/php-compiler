--TEST--
AOT: phpc.json includes bundle helper before entry (issue #452)
--FILE--
<?php
echo $greeting, 'World';
--INCLUDE--
<?php
$greeting = 'Hello ';
--EXPECT--
Hello World
