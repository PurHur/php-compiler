--TEST--
base_convert/addcslashes/date_format/hash_file Zend stub named args (#23507)
--FILE--
<?php
function dumpParams(string $fn): void
{
    $r = new ReflectionFunction($fn);
    echo $fn, ':', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
}

dumpParams('base_convert');
dumpParams('addcslashes');
dumpParams('date_format');
dumpParams('hash_file');

echo base_convert(num: 'a', from_base: 16, to_base: 10), "\n";
echo addcslashes(string: 'a.b', characters: '.'), "\n";
$d = date_create('2020-01-02');
echo date_format(object: $d, format: 'Y'), "\n";
$p = sys_get_temp_dir().'/hf23507-'.getmypid().'.txt';
file_put_contents($p, 'abc');
echo hash_file(algo: 'sha256', filename: $p), "\n";
unlink($p);

try {
    base_convert(number: 'a', frombase: 16, tobase: 10);
    echo "legacy base_convert number accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    addcslashes(str: 'a.b', characters: '.');
    echo "legacy addcslashes str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    $q = sys_get_temp_dir().'/hf23507-legacy-'.getmypid().'.txt';
    file_put_contents($q, 'abc');
    hash_file(algo: 'sha256', filename: $q, raw_output: false);
    echo "legacy hash_file raw_output accepted\n";
    unlink($q);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
    if (isset($q) && is_file($q)) {
        unlink($q);
    }
}
--EXPECT--
base_convert:num,from_base,to_base
addcslashes:string,characters
date_format:object,format
hash_file:algo,filename,binary,options
10
a\.b
2020
ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad
Unknown named parameter $number
Unknown named parameter $str
Unknown named parameter $raw_output
