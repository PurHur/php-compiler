--TEST--
stdlib extract() and compact() for template-style locals
--FILE--
<?php
$t = array('name' => 'Dev', 'role' => 'admin');
$n = extract($t);
echo $name, "\n";
echo $role, "\n";
echo $n, "\n";
$c = compact('name', 'role');
echo $c['name'], "\n";
echo $c['role'], "\n";
--EXPECT--
Dev
admin
2
Dev
admin
