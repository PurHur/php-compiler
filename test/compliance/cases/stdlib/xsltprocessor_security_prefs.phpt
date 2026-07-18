--TEST--
stdlib XSLTProcessor hasExsltSupport/setSecurityPrefs/getSecurityPrefs (#20392, ext/xsl/xsltprocessor.c)
--SKIPIF--
<?php
if (!extension_loaded('xsl') || !extension_loaded('dom') || !class_exists('XSLTProcessor', false)) {
    echo 'skip';
}
?>
--FILE--
<?php
$p = new XSLTProcessor();
echo 'has_exslt=', method_exists($p, 'hasExsltSupport') ? '1' : '0', "\n";
echo 'has_set=', method_exists($p, 'setSecurityPrefs') ? '1' : '0', "\n";
echo 'has_get=', method_exists($p, 'getSecurityPrefs') ? '1' : '0', "\n";
echo 'default=', $p->getSecurityPrefs(), "\n";
echo 'exslt=', $p->hasExsltSupport() ? '1' : '0', "\n";
$old = $p->setSecurityPrefs(XSL_SECPREF_READ_FILE);
echo 'old=', $old, ' now=', $p->getSecurityPrefs(), "\n";
?>
--EXPECT--
has_exslt=1
has_set=1
has_get=1
default=44
exslt=1
old=44 now=2
