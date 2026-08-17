<?php
/**
 * Maintainer gap #31806: RecursiveTreeIterator::setPrefixPart($part, null) soft-null.
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    $name = E_DEPRECATED === $no ? 'E_DEPRECATED' : (string) $no;
    echo "ERR:$name:$str\n";
    return true;
});

$it = new RecursiveArrayIterator(['a' => ['b' => 1]]);
$t = new RecursiveTreeIterator($it);
$t->setPrefixPart(RecursiveTreeIterator::PREFIX_LEFT, null);
echo "ok\n";
