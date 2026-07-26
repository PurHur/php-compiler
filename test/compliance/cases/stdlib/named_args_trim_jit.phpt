--TEST--
trim/ltrim/rtrim named string/characters; reject phantom mode (JIT, issue #23224)
--FILE--
<?php
foreach (['trim', 'ltrim', 'rtrim'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), PHP_EOL;
}
echo trim(string: ' x ', characters: ' '), PHP_EOL;
echo ltrim(string: ' x ', characters: ' '), PHP_EOL;
echo rtrim(string: ' x ', characters: ' '), PHP_EOL;
try {
    trim(string: ' x ', mode: 1);
    echo "mode_accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
trim:string,characters
ltrim:string,characters
rtrim:string,characters
x
x 
 x
Unknown named parameter $mode
