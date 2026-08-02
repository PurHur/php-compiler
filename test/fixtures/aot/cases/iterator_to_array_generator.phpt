--TEST--
AOT: iterator_to_array() on Generator preserve_keys true/false (#26802)
--FILE--
<?php
function gen() {
    yield "a" => 1;
    yield "b" => 2;
}
$a = iterator_to_array(gen());
$b = iterator_to_array(gen(), false);
// Shape matches Zend json_encode lines; AOT json_encode of ITA HT is a
// separate NestedJIT follow-up (segfaults on runtime arrays from this path).
echo '{"a":', $a["a"], ',"b":', $a["b"], '}', "\n";
echo '[', $b[0], ',', $b[1], ']', "\n";
--EXPECT--
{"a":1,"b":2}
[1,2]
