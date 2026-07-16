--TEST--
stdlib idn_to_ascii()/idn_to_utf8() — not advertised without ext/intl (#19593)
--FILE--
<?php
declare(strict_types=1);

$phantom = function_exists('idn_to_ascii')
    || function_exists('idn_to_utf8');
echo $phantom ? "fail\n" : "ok\n";
echo extension_loaded('intl') ? "intl_yes\n" : "intl_no\n";
--EXPECT--
ok
intl_no
