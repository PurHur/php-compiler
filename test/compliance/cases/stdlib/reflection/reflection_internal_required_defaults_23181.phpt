--TEST--
Reflection: internal required counts and defaults match Zend stubs (#23181)
--FILE--
<?php
foreach (['substr', 'json_encode', 'json_decode', 'explode', 'preg_match', 'hash', 'openssl_encrypt', 'array_slice'] as $f) {
    $rf = new ReflectionFunction($f);
    echo $f, ' req=', $rf->getNumberOfRequiredParameters(), ' num=', $rf->getNumberOfParameters();
    foreach ($rf->getParameters() as $p) {
        echo ' [', $p->getName(), ' opt=', $p->isOptional() ? 'Y' : 'N';
        if ($p->isDefaultValueAvailable()) {
            $v = $p->getDefaultValue();
            if (is_array($v)) {
                echo ' def=array';
            } else {
                echo ' def=', var_export($v, true);
            }
        }
        echo ']';
    }
    echo "\n";
}
--EXPECT--
substr req=2 num=3 [string opt=N] [offset opt=N] [length opt=Y def=NULL]
json_encode req=1 num=3 [value opt=N] [flags opt=Y def=0] [depth opt=Y def=512]
json_decode req=1 num=4 [json opt=N] [associative opt=Y def=NULL] [depth opt=Y def=512] [flags opt=Y def=0]
explode req=2 num=3 [separator opt=N] [string opt=N] [limit opt=Y def=9223372036854775807]
preg_match req=2 num=5 [pattern opt=N] [subject opt=N] [matches opt=Y def=NULL] [flags opt=Y def=0] [offset opt=Y def=0]
hash req=2 num=4 [algo opt=N] [data opt=N] [binary opt=Y def=false] [options opt=Y def=array]
openssl_encrypt req=3 num=8 [data opt=N] [cipher_algo opt=N] [passphrase opt=N] [options opt=Y def=0] [iv opt=Y def=''] [tag opt=Y def=NULL] [aad opt=Y def=''] [tag_length opt=Y def=16]
array_slice req=2 num=4 [array opt=N] [offset opt=N] [length opt=Y def=NULL] [preserve_keys opt=Y def=false]
