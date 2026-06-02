--TEST--
Language: BackedEnum::from() throws ValueError (#3114)
--FILE--
<?php
enum Color: string { case Red = 'red'; }
try {
    Color::from('missing');
    echo 'no throw';
} catch (ValueError $e) {
    echo $e->getMessage();
}
--EXPECT--
"missing" is not a valid backing value for enum Color
