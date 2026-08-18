--TEST--
is_uploaded_file named filename argument (JIT, issue #28853)
--JIT--
--FILE--
<?php
var_export(is_uploaded_file(filename: '/nope'));
echo PHP_EOL;
$rf = new ReflectionFunction('is_uploaded_file');
foreach ($rf->getParameters() as $p) {
    echo 'is_uploaded_file:', $p->getName(), PHP_EOL;
}
try {
    is_uploaded_file(path: '/nope');
    echo "path accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
false
is_uploaded_file:filename
Unknown named parameter $path
