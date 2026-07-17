--TEST--
Spoofchecker isSuspicious/areConfusable (#20035, deferred #6171)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Spoofchecker withheld until extension_loaded(\'intl\') (#19670/#20035)';
}
?>
--FILE--
<?php
echo 'spoof=', (int) class_exists('Spoofchecker', false), "\n";
$s = new Spoofchecker();
echo 'clean=', (int) $s->isSuspicious('paypal.com'), "\n";
$mixed = "p\xD0\xB0ypal.com";
echo 'mixed=', (int) $s->isSuspicious($mixed), "\n";
echo 'conf=', (int) $s->areConfusable('paypal', "\xCF\x81aypal"), "\n";
echo 'ss=', Spoofchecker::SINGLE_SCRIPT, "\n";
?>
--EXPECT--
spoof=1
clean=0
mixed=1
conf=1
ss=16
