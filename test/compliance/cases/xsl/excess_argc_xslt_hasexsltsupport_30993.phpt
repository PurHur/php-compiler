--TEST--
XSLTProcessor::hasExsltSupport() excess argc → ArgumentCountError (#30993)
--SKIPIF--
<?php
if (!extension_loaded('xsl') || !class_exists('XSLTProcessor', false)) {
    echo 'skip';
}
?>
--RUNFILE--
../../../repro/maintainer_gap_xslt_hasexsltsupport_excess_argc.php
--EXPECT--
XSLTProcessor::hasExsltSupport() expects exactly 0 arguments, 1 given
XSLTProcessor::hasExsltSupport() expects exactly 0 arguments, 2 given
ok=1
