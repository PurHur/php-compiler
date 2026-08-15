<?php
/**
 * #31091 — DOMCharacterData mutators excess argc → Zend ArgumentCountError
 * (re-#31011 / re-#30616).
 *
 * php-src: ext/dom/characterdata.c / php_dom.stub.php
 */
error_reporting(E_ALL);
function msg(callable $fn): void
{
    try {
        $fn();
        echo "NOERR\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}

$dom = new DOMDocument();
$dom->loadXML('<r>hello</r>');
$t = $dom->documentElement->firstChild;

msg(static function () use ($t) {
    $t->substringData(0, 1, 1);
});
msg(static function () use ($t) {
    $t->appendData('x', 1);
});
msg(static function () use ($t) {
    $t->deleteData(0, 1, 1);
});
msg(static function () use ($t) {
    $t->insertData(0, '!', 1);
});
msg(static function () use ($t) {
    $t->replaceData(0, 1, '!', 1);
});

// Surplus args must not mutate; legal arities still work.
echo $t->data, "\n";
echo $t->substringData(0, 2), "\n";
$t->appendData('!');
echo $t->data, "\n";
$t->insertData(0, '[');
$t->deleteData(1, 1);
$t->replaceData(1, 1, 'E');
echo $t->data, "\n";
