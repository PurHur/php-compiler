--TEST--
AOT: get_defined_constants(true) extension module buckets (#17799)
--FILE--
<?php
$c = get_defined_constants(true);
echo isset($c['pcre']['PREG_PATTERN_ORDER']) ? "pcre_ok\n" : "pcre_bad\n";
echo isset($c['random']['MT_RAND_MT19937']) ? "random_ok\n" : "random_bad\n";
echo isset($c['xml']['XML_ERROR_NONE']) ? "xml_ok\n" : "xml_bad\n";
echo isset($c['sockets']['AF_INET']) ? "sockets_ok\n" : "sockets_bad\n";
--EXPECT--
pcre_ok
random_ok
xml_ok
sockets_ok
