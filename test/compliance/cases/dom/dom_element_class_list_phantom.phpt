--TEST--
stdlib DOMElement::$classList phantom — undefined on PROFILE≥8.4 (php-src php_dom.stub.php; #28227)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo class_exists('DOMTokenList') ? '1' : '0', "\n";
echo class_exists('Dom\\TokenList') ? '1' : '0', "\n";
echo (new ReflectionClass(DOMElement::class))->hasProperty('classList') ? '1' : '0', "\n";
$dom = new DOMDocument();
$el = $dom->createElement('div');
$el->setAttribute('class', 'a b');
set_error_handler(static function (int $n, string $m): bool {
    echo 'W:', $m, "\n";

    return true;
});
var_export($el->classList);
echo "\n";
--EXPECT--
0
1
0
W:Undefined property: DOMElement::$classList
NULL
