--TEST--
stdlib DOMNode PHP 8.4 methods — not advertised on PHP 8.2 reference profile (#17470, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$el = $doc->createElement('x');
$fail = false;
foreach (['contains', 'replaceChildren'] as $method) {
    if (method_exists($el, $method)) {
        $fail = true;
    }
}
echo $fail ? "fail\n" : "ok\n";
--EXPECT--
ok
