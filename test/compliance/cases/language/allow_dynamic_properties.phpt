--TEST--
Language: #[\AllowDynamicProperties] permits undeclared writes (#3467)
--FILE--
<?php
#[\AllowDynamicProperties]
class C {}
$o = new C;
$o->dynamic = 'ok';
echo $o->dynamic, "\n";
--EXPECT--
ok
