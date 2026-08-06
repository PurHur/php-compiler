--TEST--
stdlib Dom\TokenList / createElement JIT — living DOM (#17130, #28227, ext/dom)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div id="d"></div></body></html>'
);
$el = $html->getElementById('d');
$el->classList->add('a', 'b');
echo $el->classList->contains('a') ? '1' : '0', $el->classList->item(0), "\n";
?>
--EXPECT--
1a
