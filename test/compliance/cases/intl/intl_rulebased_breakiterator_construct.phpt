--TEST--
IntlRuleBasedBreakIterator __construct/getRules/getBinaryRules (#20907)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlRuleBasedBreakIterator withheld until extension_loaded(\'intl\')';
}
?>
--RUNFILE--
intl_rulebased_breakiterator_construct.php
--EXPECT--
class=IntlRuleBasedBreakIterator
setText=ok
first=0
rules=[[:Letter:]];
binary=false
