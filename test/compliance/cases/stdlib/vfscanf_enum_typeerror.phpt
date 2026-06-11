--TEST--
stdlib vfscanf() — enum format operand TypeError (#6174, php-src-strict)
--FILE--
<?php
enum E: string { case A = '%d'; }
$fp = fopen('php://memory', 'r+');
fwrite($fp, '1');
rewind($fp);
$n = 0;
try {
    vfscanf($fp, E::A, $n);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
vfscanf(): Argument #2 ($format) must be of type string, E given

