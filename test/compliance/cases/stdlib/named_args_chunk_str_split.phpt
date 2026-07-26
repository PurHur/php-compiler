--TEST--
chunk_split/str_split named string/length/separator arguments (VM, issue #23206)
--FILE--
<?php
var_export(chunk_split(string: 'abcd', length: 2, separator: '|'));
echo PHP_EOL;
var_export(str_split(string: 'abcd', length: 2));
echo PHP_EOL;
$rf = new ReflectionFunction('chunk_split');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
$rf = new ReflectionFunction('str_split');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
try {
    chunk_split(str: 'abcd');
    echo "str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
try {
    str_split(split_length: 2);
    echo "split_length accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
'ab|cd|'
array (
  0 => 'ab',
  1 => 'cd',
)
string
length
separator
string
length
Unknown named parameter $str
Unknown named parameter $split_length
