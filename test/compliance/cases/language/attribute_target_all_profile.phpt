--TEST--
Language: Attribute::TARGET_ALL ConstFetch matches Reflection on ≤8.4 (#20727)
--FILE--
<?php
$r = (new ReflectionClass('Attribute'))->getConstant('TARGET_ALL');
$d = Attribute::TARGET_ALL;
$tc = (new ReflectionClass('Attribute'))->hasConstant('TARGET_CONSTANT');
$repR = (new ReflectionClass('Attribute'))->getConstant('IS_REPEATABLE');
$repD = Attribute::IS_REPEATABLE;
echo "reflection_ALL=$r direct_ALL=$d has_TARGET_CONSTANT=".($tc ? '1' : '0')
    ." match=".($r === $d ? 'yes' : 'NO')
    ." IS_REPEATABLE_r=$repR IS_REPEATABLE_d=$repD"
    ." rep_match=".($repR === $repD ? 'yes' : 'NO')."\n";
--EXPECT--
reflection_ALL=63 direct_ALL=63 has_TARGET_CONSTANT=0 match=yes IS_REPEATABLE_r=64 IS_REPEATABLE_d=64 rep_match=yes
