--TEST--
Stdlib: get_declared_interfaces() lists Zend builtin interfaces (VM, #11247)
--FILE--
<?php
foreach (['DOMChildNode', 'DOMParentNode', 'Reflector', 'SeekableIterator'] as $iface) {
    echo interface_exists($iface) ? '1' : '0';
    echo in_array($iface, get_declared_interfaces(), true) ? '1' : '0';
}
echo "\n";
echo count(get_declared_interfaces()) >= 25 ? '1' : '0', "\n";
--EXPECT--
11111111
1
