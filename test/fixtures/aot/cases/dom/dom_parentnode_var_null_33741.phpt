--TEST--
AOT: ParentNode append/prepend/replaceChildren variable-null TypeError (#33741, ext/dom/parentnode.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$el = $d->documentElement;
$n = null;
try {
    $el->append($n);
    echo "append=fail\n";
} catch (Throwable $ex) {
    echo 'append=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $el->prepend($n);
    echo "prepend=fail\n";
} catch (Throwable $ex) {
    echo 'prepend=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $el->replaceChildren($n);
    echo "replaceChildren=fail\n";
} catch (Throwable $ex) {
    echo 'replaceChildren=', get_class($ex), ':', $ex->getMessage(), "\n";
}
$miss = $d->getElementById('nope');
try {
    $el->append($miss);
    echo "id=fail\n";
} catch (Throwable $ex) {
    echo 'id=', get_class($ex), ':', $ex->getMessage(), "\n";
}
--EXPECT--
append=TypeError:DOMElement::append(): Argument #1 must be of type DOMNode|string, null given
prepend=TypeError:DOMElement::prepend(): Argument #1 must be of type DOMNode|string, null given
replaceChildren=TypeError:DOMElement::replaceChildren(): Argument #1 must be of type DOMNode|string, null given
id=TypeError:DOMElement::append(): Argument #1 must be of type DOMNode|string, null given
