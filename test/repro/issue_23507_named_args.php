<?php
/**
 * #23507 — Zend stub named args for base_convert/addcslashes/date_format/hash_file.
 * php-src: ext/standard/basic_functions.stub.php, ext/date/php_date.stub.php, ext/hash/hash.stub.php
 */
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
    echo 'legacy_base_convert=', $e->getMessage(), "\n";
}
try {
    addcslashes(str: 'a.b', characters: '.');
    echo "legacy addcslashes str accepted\n";
} catch (Throwable $e) {
    echo 'legacy_addcslashes=', $e->getMessage(), "\n";
}
