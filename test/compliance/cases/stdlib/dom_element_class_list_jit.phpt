--TEST--
stdlib DOMTokenList / createElement JIT — living DOM (#17130, ext/dom)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$dom = new DOMDocument();
$el = $dom->createElement('div');
$dom->appendChild($el);
$el->classList->add('a', 'b');
echo $el->classList->contains('a') ? '1' : '0', $el->classList->item(0), "\n";
?>
--EXPECT--
1a
