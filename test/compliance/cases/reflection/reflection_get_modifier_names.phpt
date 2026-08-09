--TEST--
Reflection::getModifierNames() maps modifier bits like Zend (#22127; *(set) names PROFILE≥8.5 only #29188)
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', class_exists('Reflection') ? '1' : '0', "\n";
echo 'method=', method_exists('Reflection', 'getModifierNames') ? '1' : '0', "\n";

echo '17=', json_encode(Reflection::getModifierNames(17)), "\n";
echo '65=', json_encode(Reflection::getModifierNames(65)), "\n";
echo '49=', json_encode(Reflection::getModifierNames(49)), "\n";
echo '128=', json_encode(Reflection::getModifierNames(128)), "\n";
echo '65536=', json_encode(Reflection::getModifierNames(65536)), "\n";
echo '7=', json_encode(Reflection::getModifierNames(7)), "\n";
echo '512=', json_encode(Reflection::getModifierNames(512)), "\n";
echo '2048=', json_encode(Reflection::getModifierNames(2048)), "\n";
echo '4096=', json_encode(Reflection::getModifierNames(4096)), "\n";
?>
--EXPECT--
exists=1
method=1
17=["public","static"]
65=["abstract","public"]
49=["final","public","static"]
128=["readonly"]
65536=["readonly"]
7=[]
512=["virtual"]
2048=[]
4096=[]
