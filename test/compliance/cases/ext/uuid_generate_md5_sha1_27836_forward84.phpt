--TEST--
ext uuid_generate_md5/sha1 DNS namespace fixtures (issue #27836)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['uuid_generate_md5', 'uuid_generate_sha1'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
$ns = '6ba7b810-9dad-11d1-80b4-00c04fd430c8'; // DNS namespace
echo uuid_generate_md5($ns, 'php.net'), "\n";
echo uuid_generate_sha1($ns, 'php.net'), "\n";
echo uuid_generate_md5(uuid_ns: $ns, name: 'www.example.org'), "\n";
echo uuid_generate_sha1(uuid_ns: $ns, name: 'www.example.org'), "\n";
$rf = new ReflectionFunction('uuid_generate_md5');
echo $rf->getNumberOfParameters(), "\n";
echo $rf->getParameters()[0]->getName(), "\n";
echo $rf->getParameters()[1]->getName(), "\n";
echo (string) $rf->getReturnType(), "\n";
try {
    uuid_generate_md5('not-a-uuid', 'x');
    echo "bad\n";
} catch (ValueError $e) {
    echo "md5_ns_err\n";
}
try {
    uuid_generate_sha1('not-a-uuid', 'x');
    echo "bad\n";
} catch (ValueError $e) {
    echo "sha1_ns_err\n";
}
?>
--EXPECT--
uuid_generate_md5=1
uuid_generate_sha1=1
11a38b9a-b3da-360f-9353-a5a725514269
c4a760a8-dbcf-5254-a0d9-6a4474bd1b62
0012416f-9eec-3ed4-a8b0-3bceecde1cd9
74738ff5-5367-5958-9aee-98fffdcd1876
2
uuid_ns
name
string
md5_ns_err
sha1_ns_err
