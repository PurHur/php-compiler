<?php
/**
 * #24804 AOT: invalid createElement literal throws; valid literal still materializes.
 */
$d = new DOMDocument();
try {
    $d->createElement('123bad');
    echo "no_throw\n";
} catch (DOMException $e) {
    echo "ex=", $e->getCode(), "\n";
}
echo $d->createElement('ok')->tagName, "\n";
