--TEST--
md5/sha1 named string:/binary: arguments (VM, issue #23227)
--FILE--
<?php
foreach (['md5', 'sha1'] as $fn) {
    echo $fn, ':', $fn(string: 'x'), PHP_EOL;
    echo $fn, '_raw:', bin2hex($fn(string: 'x', binary: true)), PHP_EOL;
    $rf = new ReflectionFunction($fn);
    foreach ($rf->getParameters() as $p) {
        echo $fn, '_param:', $p->getName(), PHP_EOL;
    }
    try {
        $fn(str: 'x');
        echo $fn, "_str accepted\n";
    } catch (Throwable $e) {
        echo $fn, '_str:', $e->getMessage(), PHP_EOL;
    }
    try {
        $fn(string: 'x', raw_output: true);
        echo $fn, "_raw_output accepted\n";
    } catch (Throwable $e) {
        echo $fn, '_raw_output:', $e->getMessage(), PHP_EOL;
    }
}
--EXPECT--
md5:9dd4e461268c8034f5c8564e155c67a6
md5_raw:9dd4e461268c8034f5c8564e155c67a6
md5_param:string
md5_param:binary
md5_str:Unknown named parameter $str
md5_raw_output:Unknown named parameter $raw_output
sha1:11f6ad8ec52a2984abaafd7c3b516503785c2072
sha1_raw:11f6ad8ec52a2984abaafd7c3b516503785c2072
sha1_param:string
sha1_param:binary
sha1_str:Unknown named parameter $str
sha1_raw_output:Unknown named parameter $raw_output
