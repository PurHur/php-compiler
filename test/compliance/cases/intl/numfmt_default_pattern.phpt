--TEST--
NumberFormatter getPattern default for DECIMAL/PERCENT/CURRENCY (#21113)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$dec = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo 'decimal=', $dec->getPattern(), "\n";
$pct = new NumberFormatter('en_US', NumberFormatter::PERCENT);
echo 'percent=', $pct->getPattern(), "\n";
$cur = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
echo 'currency=', $cur->getPattern(), "\n";
$sci = new NumberFormatter('en_US', NumberFormatter::SCIENTIFIC);
echo 'scientific=', $sci->getPattern(), "\n";
$created = NumberFormatter::create('de_DE', NumberFormatter::DECIMAL);
echo 'create_de=', $created->getPattern(), "\n";
echo 'proc=', numfmt_get_pattern($dec), "\n";
$dec->setPattern('#,##0.00');
echo 'set_rt=', $dec->getPattern(), "\n";
?>
--EXPECT--
decimal=#,##0.###
percent=#,##0%
currency=¤#,##0.00
scientific=#E0
create_de=#,##0.###
proc=#,##0.###
set_rt=#,##0.00
