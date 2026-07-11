--TEST--
get_defined_constants(true) pcre/random/xml/sockets/readline/xsl module buckets (#17799, ext/standard/basic_functions.c)
--FILE--
<?php
$c = get_defined_constants(true);
echo isset($c['pcre']) && isset($c['pcre']['PREG_PATTERN_ORDER']) ? "pcre_ok\n" : "pcre_bad\n";
echo isset($c['standard']['PREG_PATTERN_ORDER']) ? "preg_in_standard\n" : "preg_not_in_standard\n";
echo isset($c['random']) && isset($c['random']['MT_RAND_MT19937']) ? "random_ok\n" : "random_bad\n";
echo isset($c['xml']) && isset($c['xml']['XML_ERROR_NONE']) ? "xml_ok\n" : "xml_bad\n";
echo isset($c['sockets']) && isset($c['sockets']['AF_INET']) ? "sockets_ok\n" : "sockets_bad\n";
echo isset($c['readline']) && isset($c['readline']['READLINE_LIB']) ? "readline_ok\n" : "readline_bad\n";
if (extension_loaded('xsl')) {
    echo isset($c['xsl']) && isset($c['xsl']['XSL_CLONE_AUTO']) ? "xsl_ok\n" : "xsl_bad\n";
} else {
    echo !isset($c['xsl']) ? "xsl_ok\n" : "xsl_bad\n";
}
--EXPECT--
pcre_ok
preg_not_in_standard
random_ok
xml_ok
sockets_ok
readline_ok
xsl_ok
