--TEST--
MessageFormatter plural/select ICU skeletons (#21099)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$fmt = new MessageFormatter('en_US', '{n, plural, =0{none} one{# item} other{# items}}');
echo 'p0=', $fmt->format(['n' => 0]), "\n";
echo 'p1=', $fmt->format(['n' => 1]), "\n";
echo 'p5=', $fmt->format(['n' => 5]), "\n";
echo 'hello=', (new MessageFormatter('en_US', 'Hello {name}'))->format(['name' => 'Ada']), "\n";
$sel = new MessageFormatter('en_US', '{g, select, female{her} male{his} other{their}}');
echo 'sel_f=', $sel->format(['g' => 'female']), "\n";
echo 'sel_o=', $sel->format(['g' => 'x']), "\n";
echo 'sent=', (new MessageFormatter('en_US', 'You have {n, plural, one{# apple} other{# apples}}.'))->format(['n' => 2]), "\n";
echo 'proc=', msgfmt_format_message('en_US', '{n, plural, one{#x} other{#y}}', ['n' => 1]), "\n";
?>
--EXPECT--
p0=none
p1=1 item
p5=5 items
hello=Hello Ada
sel_f=her
sel_o=their
sent=You have 2 apples.
proc=1x
