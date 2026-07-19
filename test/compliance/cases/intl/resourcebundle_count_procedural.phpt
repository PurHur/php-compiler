--TEST--
resourcebundle_count() procedural alias + Countable (#20781)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip ResourceBundle withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
echo 'fn=', function_exists('resourcebundle_count') ? '1' : '0', "\n";
$rb = ResourceBundle::create('en', 'ICUDATA-zone');
if (false === $rb || null === $rb) {
    $rb = ResourceBundle::create('en', null);
}
echo 'method=', $rb->count(), "\n";
echo 'proc=', resourcebundle_count($rb), "\n";
echo 'countable=', (int) ($rb instanceof Countable), "\n";
echo 'match=', (int) ($rb->count() === resourcebundle_count($rb)), "\n";
?>
--EXPECT--
fn=1
method=1
proc=1
countable=1
match=1
