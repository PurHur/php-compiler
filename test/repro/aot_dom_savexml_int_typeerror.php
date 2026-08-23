<?php
/**
 * AOT: saveXML(int) / saveHTML(int) / saveXML(string) TypeError on ?DOMNode (#31396).
 * php-src ext/dom/php_dom.stub.php — argument #1 is ?DOMNode, not options.
 */
declare(strict_types=1);

$d = new DOMDocument();
$d->loadXML('<r><a/></r>');

try {
    $d->saveXML(1);
    echo "saveXML_int=fail\n";
} catch (Throwable $ex) {
    echo 'saveXML_int=', get_class($ex), ':', $ex->getMessage(), "\n";
}

try {
    $d->saveHTML(1);
    echo "saveHTML_int=fail\n";
} catch (Throwable $ex) {
    echo 'saveHTML_int=', get_class($ex), ':', $ex->getMessage(), "\n";
}

try {
    $d->saveXML('x');
    echo "saveXML_string=fail\n";
} catch (Throwable $ex) {
    echo 'saveXML_string=', get_class($ex), ':', $ex->getMessage(), "\n";
}
