--TEST--
Language: illegal string offset dims throw TypeError; isset false (zend_execute.c, #22895)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});

$s = 'ab';

try {
    var_export($s['foo']);
    echo "\n";
} catch (Throwable $e) {
    echo 'read-str:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $s['foo'] = 'z';
    echo 'write-str:', $s, "\n";
} catch (Throwable $e) {
    echo 'write-str:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    var_export($s[new stdClass]);
    echo "\n";
} catch (Throwable $e) {
    echo 'read-obj:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    var_export($s[[1]]);
    echo "\n";
} catch (Throwable $e) {
    echo 'read-arr:', get_class($e), ':', $e->getMessage(), "\n";
}

echo 'isset-foo:', var_export(isset($s['foo']), true), "\n";
echo 'empty-foo:', var_export(empty($s['foo']), true), "\n";
echo 'isset-obj:', var_export(isset($s[new stdClass]), true), "\n";
echo 'isset-arr:', var_export(isset($s[[1]]), true), "\n";
echo 'isset-0:', var_export(isset($s['0']), true), "\n";
echo 'read-0:', var_export($s['0']), "\n";
echo 'read-1:', var_export($s['1']), "\n";

$t = 'ab';
$t['1a'] = 'Z';
echo 'trailing:', $t, "\n";
--EXPECT--
read-str:TypeError:Cannot access offset of type string on string
write-str:TypeError:Cannot access offset of type string on string
read-obj:TypeError:Cannot access offset of type stdClass on string
read-arr:TypeError:Cannot access offset of type array on string
isset-foo:false
empty-foo:true
isset-obj:false
isset-arr:false
isset-0:true
read-0:'a'
read-1:'b'
W:Illegal string offset "1a"
trailing:aZ
