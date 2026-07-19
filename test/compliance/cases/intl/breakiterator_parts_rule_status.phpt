--TEST--
IntlPartsIterator::getBreakIterator/getRuleStatus + RBBI rule status (#20883)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlPartsIterator rule-status withheld until extension_loaded(\'intl\') (#19670/#20883)';
}
?>
--RUNFILE--
breakiterator_parts_rule_status.php
--EXPECT--
owner_same=1
pi_status_method=1
pi_vec_method=0
rbbi_status_method=1
rbbi_vec_method=1
Hello:200|,:0| :0|world:200|!:0
rbbi_at_hello=200
rbbi_vec=[200]
KEY_LEFT=1
