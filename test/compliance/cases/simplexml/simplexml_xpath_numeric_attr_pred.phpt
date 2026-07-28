--TEST--
SimpleXMLElement::xpath() [@attr=N] unquoted numeric equality (#24340)
--FILE--
<?php
$x = simplexml_load_string('<r><a id="1">x</a><a id="2">y</a><a id="1.0">z</a><a id="01">w</a></r>');
$quoted = $x->xpath('//a[@id="1"]');
$numeric = $x->xpath('//a[@id=1]');
echo 'quoted=', is_array($quoted) ? count($quoted) : 'false', "\n";
echo 'numeric=', is_array($numeric) ? count($numeric) : 'false', "\n";
echo 'texts=';
if (is_array($numeric)) {
    $parts = [];
    foreach ($numeric as $n) {
        $parts[] = (string) $n;
    }
    echo implode(',', $parts);
}
echo "\n";
?>
--EXPECT--
quoted=1
numeric=3
texts=x,z,w
