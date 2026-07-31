--TEST--
mb_detect_encoding named arguments + Reflection (VM, issue #23623)
--FILE--
<?php
$rf = new ReflectionFunction('mb_detect_encoding');
echo implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
echo mb_detect_encoding(string: 'abc', encodings: ['UTF-8']), "\n";
echo mb_detect_encoding(string: 'abc', encodings: 'ASCII,UTF-8', strict: true), "\n";
try {
    mb_detect_encoding(str: 'abc');
    echo "legacy str accepted\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
string,encodings,strict
UTF-8
ASCII
Error
