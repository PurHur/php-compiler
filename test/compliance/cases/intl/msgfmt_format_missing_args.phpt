--TEST--
MessageFormatter::format() missing args leave stripped {n}/{name} not ICU skeleton (#22946)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$f = MessageFormatter::create('en_US', 'Item {0,number} of {1,number}');
echo 'both=', $f->format([3, 10]), "\n";
echo 'one=', $f->format([3]), "\n";
echo 'none=', $f->format([]), "\n";
$f2 = MessageFormatter::create('en_US', '{0,select,male{he}female{she}other{they}} went');
echo 'selMiss=', $f2->format([]), "\n";
$f3 = MessageFormatter::create('en_US', 'Hi {name}');
echo 'named=', $f3->format(['name' => 'Bob']), "\n";
$f4 = MessageFormatter::create('en_US', 'Hi {name,select,other{X}}');
echo 'namedMiss=', $f4->format([]), "\n";
$f5 = MessageFormatter::create('en_US', '{0,plural,one{# item} other{# items}}');
echo 'plMiss=', $f5->format([]), "\n";
echo 'proc=', msgfmt_format_message('en_US', 'Item {0,number} of {1,number}', [3]), "\n";
?>
--EXPECT--
both=Item 3 of 10
one=Item 3 of {1}
none=Item {0} of {1}
selMiss={0} went
named=Hi Bob
namedMiss=Hi {name}
plMiss={0}
proc=Item 3 of {1}
