--TEST--
stdlib stream_get_contents Reflection length/offset defaults (#25134)
--FILE--
<?php
$r = new ReflectionFunction('stream_get_contents');
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=' . json_encode($p->getDefaultValue());
    } elseif ($p->isOptional()) {
        echo '=?';
    }
    echo "\n";
}
$s = fopen('php://memory', 'r+');
fwrite($s, 'abcdef');
rewind($s);
echo 'omit=' . var_export(stream_get_contents($s), true) . "\n";
rewind($s);
echo 'named=' . var_export(stream_get_contents(stream: $s, length: 3), true) . "\n";
--EXPECT--
stream
length=null
offset=-1
omit='abcdef'
named='abc'
