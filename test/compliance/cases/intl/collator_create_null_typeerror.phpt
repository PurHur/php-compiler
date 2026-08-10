--TEST--
collator_create / Collator::create / __construct(null) TypeError (#29933)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Collator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'proc' => static fn () => collator_create(null),
    'create' => static fn () => Collator::create(null),
    'new' => static fn () => new Collator(null),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' OK ', get_class($r), "\n";
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
$ok = collator_create('en');
echo 'ok=', (int) ($ok instanceof Collator), "\n";
?>
--EXPECT--
proc TypeError null locale
create TypeError null locale
new TypeError null locale
ok=1
