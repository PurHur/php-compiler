--TEST--
IntlChar charName/hasBinaryProperty/isalpha/toupper surface (#20730)
--FILE--
<?php
echo 'charName=', IntlChar::charName(IntlChar::ord('A')), "\n";
echo 'copy=', IntlChar::charName('©'), "\n";
echo 'alpha=', (int) IntlChar::isalpha('Z'), ' digit=', (int) IntlChar::isdigit('7'), ' notdigit=', (int) IntlChar::isdigit('A'), "\n";
echo 'up=', IntlChar::toupper(0x62), ' down=', IntlChar::tolower(0x41), "\n";
echo 'up_str=', IntlChar::toupper('b'), "\n";
echo 'prop=', (int) IntlChar::hasBinaryProperty('A', IntlChar::PROPERTY_ALPHABETIC), "\n";
?>
--EXPECT--
charName=LATIN CAPITAL LETTER A
copy=COPYRIGHT SIGN
alpha=1 digit=1 notdigit=0
up=66 down=97
up_str=B
prop=1
