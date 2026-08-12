--TEST--
locale_accept_from_http / Locale::acceptFromHttp(null) TypeError under strict_types (#29914)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance(basename(__FILE__))) {
    echo 'skip Locale withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'oop' => static fn () => Locale::acceptFromHttp(null),
    'proc' => static fn () => locale_accept_from_http(null),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' OK ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $name, ' TypeError';
        if (false !== strpos($e->getMessage(), 'null given')) {
            echo ' null';
        }
        if (false !== strpos($e->getMessage(), '($header)')) {
            echo ' header';
        }
        echo "\n";
    }
}
$ok = Locale::acceptFromHttp('en-US,en;q=0.9');
echo 'ok=', var_export($ok, true), "\n";
?>
--EXPECT--
oop TypeError null header
proc TypeError null header
ok='en_US'
