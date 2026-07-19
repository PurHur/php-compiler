<?php
/**
 * Repro #20770 — UConverter encoding introspection + subst chars + reasonText.
 * php-src: ext/intl/converter/converter.c / converter.stub.php
 */
$c = new UConverter('UTF-8', 'ISO-8859-1');
echo 'methods=', (int) method_exists($c, 'getSourceEncoding'), (int) method_exists($c, 'getDestinationEncoding'),
    (int) method_exists($c, 'setSubstChars'), (int) method_exists($c, 'getSubstChars'),
    (int) method_exists($c, 'reasonText'), "\n";
echo 'src=', $c->getSourceEncoding(), "\n";
echo 'dest=', $c->getDestinationEncoding(), "\n";
echo 'subst0=', bin2hex($c->getSubstChars()), "\n";
echo 'set=', (int) $c->setSubstChars('?'), "\n";
echo 'subst1=', $c->getSubstChars(), "\n";
echo 'reason=', UConverter::reasonText(UConverter::REASON_ILLEGAL), "\n";
echo 'clone=', UConverter::reasonText(UConverter::REASON_CLONE), "\n";
try {
    UConverter::reasonText(99);
    echo "bad_reason=ok\n";
} catch (ValueError $e) {
    echo "bad_reason=ValueError\n";
}
$bad = new UConverter('not-a-real-encoding', 'UTF-8');
echo 'bad_dest=', var_export($bad->getDestinationEncoding(), true), "\n";
echo 'bad_src=', var_export($bad->getSourceEncoding(), true), "\n";
