--TEST--
IntlCodePointBreakIterator + createCodePointInstance (#20822)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlCodePointBreakIterator withheld until extension_loaded(\'intl\') (#19670/#20822)';
}
?>
--RUNFILE--
breakiterator_codepoint.php
--EXPECT--
class=1
factory=1
inst=IntlCodePointBreakIterator
breaks=[0,1,5,6]
first=0 lastCP=-1
n1=1 lastCP=65
n2=5 lastCP=128512
n3=6 lastCP=66
n4=-1 lastCP=-1
