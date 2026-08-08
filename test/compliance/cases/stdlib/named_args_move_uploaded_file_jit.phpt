--TEST--
move_uploaded_file named from/to arguments (JIT, issue #28854)
--JIT--
--FILE--
<?php
var_export(move_uploaded_file(from: '/nope-from', to: '/nope-to'));
echo PHP_EOL;
$rf = new ReflectionFunction('move_uploaded_file');
foreach ($rf->getParameters() as $p) {
    echo 'move_uploaded_file:', $p->getName(), PHP_EOL;
}
try {
    move_uploaded_file(path: '/a', new_path: '/b');
    echo "path accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
false
move_uploaded_file:from
move_uploaded_file:to
Unknown named parameter $path
