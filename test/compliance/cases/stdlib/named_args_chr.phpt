--TEST--
chr named codepoint argument (VM, issue #23240)
--FILE--
<?php
var_export(chr(codepoint: 65));
echo PHP_EOL;
$rf = new ReflectionFunction('chr');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
try {
    chr(ascii: 65);
    echo "ascii accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
'A'
codepoint
Unknown named parameter $ascii
