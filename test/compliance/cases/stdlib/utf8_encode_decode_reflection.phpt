--TEST--
stdlib utf8_encode/utf8_decode Reflection names string (#26235)
--FILE--
<?php
declare(strict_types=1);

foreach (['utf8_encode', 'utf8_decode'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, '=', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
}
echo bin2hex(utf8_encode(string: "\xA0")), "\n";
echo bin2hex(utf8_decode(string: "\xc2\xa0")), "\n";
try {
    utf8_encode(data: "\xA0");
    echo "legacy=UNEXPECTED\n";
} catch (Error $e) {
    echo 'legacy:', $e->getMessage(), "\n";
}
--EXPECT--
utf8_encode=string
utf8_decode=string
c2a0
a0
legacy:Unknown named parameter $data
