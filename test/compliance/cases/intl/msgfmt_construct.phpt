--TEST--
MessageFormatter::__construct matches create() (#20809)
--FILE--
<?php
$a = new MessageFormatter('en_US', 'Hello {0}');
$b = MessageFormatter::create('en_US', 'Hello {0}');
echo 'new_format=';
var_export($a->format(['World']));
echo "\n";
echo 'create_format=';
var_export($b->format(['World']));
echo "\n";
echo 'new_pattern=';
var_export($a->getPattern());
echo "\n";
echo 'match=', (int) ($a->format(['World']) === $b->format(['World'])), "\n";
?>
--EXPECT--
new_format='Hello World'
create_format='Hello World'
new_pattern='Hello {0}'
match=1
