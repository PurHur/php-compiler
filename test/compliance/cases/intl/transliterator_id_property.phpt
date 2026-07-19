--TEST--
Transliterator::$id readonly property (php-src utrans_getUnicodeID; #20915)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Transliterator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$t = Transliterator::create('Latin-ASCII');
echo 'isset=', (int) isset($t->id), "\n";
echo 'id=', $t->id, "\n";
$vars = get_object_vars($t);
echo 'vars_has_id=', (int) (isset($vars['id']) && is_string($vars['id']) && $vars['id'] !== ''), "\n";
echo 'vars_id=', $vars['id'] ?? 'MISSING', "\n";
try {
    $t->id = 'x';
    echo "write=ok\n";
} catch (Throwable $e) {
    echo 'write=', $e instanceof Error ? 'Error' : get_class($e), "\n";
}
$r = Transliterator::createFromRules('a > b;');
echo 'rules_id=', $r->id, "\n";
$inv = $t->createInverse();
echo 'inverse_isset=', (int) isset($inv->id), "\n";
echo 'inverse_id=', $inv->id, "\n";
?>
--EXPECT--
isset=1
id=Latin-ASCII
vars_has_id=1
vars_id=Latin-ASCII
write=Error
rules_id=RulesTransPHP
inverse_isset=1
inverse_id=ASCII-Latin
