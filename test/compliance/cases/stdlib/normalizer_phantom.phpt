--TEST--
stdlib Normalizer / normalizer_* — not advertised without ext/intl (#19594)
--FILE--
<?php
declare(strict_types=1);

$phantom = class_exists('Normalizer', false)
    || function_exists('normalizer_normalize')
    || function_exists('normalizer_is_normalized')
    || function_exists('normalizer_get_raw_decomposition');
echo $phantom ? "fail\n" : "ok\n";
echo extension_loaded('intl') ? "intl_yes\n" : "intl_no\n";
--EXPECT--
ok
intl_no
