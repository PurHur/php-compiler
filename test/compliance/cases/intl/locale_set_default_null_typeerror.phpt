--TEST--
locale_set_default / Locale::setDefault(null) TypeError (#29932)
--SKIPIF--
<?php
if (!\function_exists('locale_set_default') || !\class_exists('Locale', false)) {
    echo 'skip ext/intl locale_set_default not available';
}
?>
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'oop' => static fn () => Locale::setDefault(null),
    'proc' => static fn () => locale_set_default(null),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' OK ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $name, ' TypeError';
        if (false !== strpos($e->getMessage(), 'null given')) {
            echo ' null';
        }
        if (false !== strpos($e->getMessage(), '($locale)')) {
            echo ' locale';
        }
        echo "\n";
    }
}
echo 'ok=', (int) locale_set_default('en_US'), "\n";
?>
--EXPECT--
oop TypeError null locale
proc TypeError null locale
ok=1
