<?php
/**
 * #30993 — XSLTProcessor::hasExsltSupport() excess argc → Zend ArgumentCountError.
 *
 * php-src: ext/xsl/php_xsl.c / xsltprocessor.c / xsl.stub.php
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

$x = new XSLTProcessor();
msg(static function () use ($x) {
    $x->hasExsltSupport(1);
});
msg(static function () use ($x) {
    $x->hasExsltSupport(1, 2);
});
$ok = $x->hasExsltSupport();
echo 'ok=', $ok ? '1' : '0', "\n";
