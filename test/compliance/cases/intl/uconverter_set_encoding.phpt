--TEST--
UConverter::setSourceEncoding/setDestinationEncoding (#20881)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip UConverter setters withheld until extension_loaded(\'intl\') (#19670/#20881)';
}
?>
--RUNFILE--
uconverter_set_encoding.php
--EXPECT--
set_src=1
set_dst=1
ok_src=1 src=utf-8
ok_dst=1 dst=iso-8859-1
bad=0
src_kept=utf-8
err=4
