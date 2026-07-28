--TEST--
libxml LIBXML_RECOVER — withheld on reference PROFILE; present under PROFILE=8.4 (#24439)
--FILE--
<?php
echo 'LIBXML_RECOVER=', defined('LIBXML_RECOVER') ? (string) constant('LIBXML_RECOVER') : 'UNDEF', "\n";
echo 'LIBXML_NOENT=', defined('LIBXML_NOENT') ? (string) constant('LIBXML_NOENT') : 'UNDEF', "\n";
?>
--EXPECT--
LIBXML_RECOVER=UNDEF
LIBXML_NOENT=2
