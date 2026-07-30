--TEST--
gzencode Reflection defaults -1/31; named encoding without level (#25012)
--FILE--
<?php
$r = new ReflectionFunction('gzencode');
foreach ($r->getParameters() as $p) {
    echo '$', $p->getName();
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
$a = gzencode(data: 'hello', encoding: ZLIB_ENCODING_GZIP);
$b = gzencode('hello', -1, ZLIB_ENCODING_GZIP);
echo 'named_encoding_len=', strlen($a), "\n";
echo 'match=', $a === $b ? '1' : '0', "\n";
--EXPECT--
$data
$level=-1
$encoding=31
named_encoding_len=25
match=1
