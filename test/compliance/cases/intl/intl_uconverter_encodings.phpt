--TEST--
UConverter getSourceEncoding/getDestinationEncoding/setSubstChars/reasonText (#20770)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip UConverter withheld until extension_loaded(\'intl\') (#19670/#20770)';
}
?>
--FILE--
<?php
$c = new UConverter('UTF-8', 'ISO-8859-1');
echo 'src=', $c->getSourceEncoding(), "\n";
echo 'dest=', $c->getDestinationEncoding(), "\n";
echo 'subst0=', bin2hex($c->getSubstChars()), "\n";
var_dump($c->setSubstChars('?'));
echo 'subst1=', $c->getSubstChars(), "\n";
var_dump($c->setSubstChars('XY'));
echo 'subst2=', $c->getSubstChars(), "\n";
echo 'reason=', UConverter::reasonText(UConverter::REASON_UNASSIGNED), "\n";
echo 'clone=', UConverter::reasonText(UConverter::REASON_CLONE), "\n";
try {
    UConverter::reasonText(99);
    echo "bad=ok\n";
} catch (ValueError $e) {
    echo "bad=ValueError\n";
}
$bad = new UConverter('not-a-real-encoding', 'UTF-8');
echo 'bad_dest=', var_export($bad->getDestinationEncoding(), true), "\n";
echo 'bad_src=', var_export($bad->getSourceEncoding(), true), "\n";
?>
--EXPECT--
src=ISO-8859-1
dest=UTF-8
subst0=1a
bool(true)
subst1=?
bool(false)
subst2=?
reason=REASON_UNASSIGNED
clone=REASON_CLONE
bad=ValueError
bad_dest=NULL
bad_src='UTF-8'
