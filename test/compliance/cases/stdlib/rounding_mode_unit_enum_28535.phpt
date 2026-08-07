--TEST--
stdlib RoundingMode unit enum + no PHP_ROUND_CEILING phantoms (#28535, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'backed=', (new ReflectionEnum(RoundingMode::class))->isBacked() ? '1' : '0', "\n";
@$v = RoundingMode::TowardsZero->value;
echo 'value=', var_export($v, true), "\n";
echo 'CEILING=', defined('PHP_ROUND_CEILING') ? 'y' : 'n', "\n";
echo 'FLOOR=', defined('PHP_ROUND_FLOOR') ? 'y' : 'n', "\n";
echo 'TOWARD=', defined('PHP_ROUND_TOWARD_ZERO') ? 'y' : 'n', "\n";
echo 'AWAY=', defined('PHP_ROUND_AWAY_FROM_ZERO') ? 'y' : 'n', "\n";
echo 'HALF_UP=', defined('PHP_ROUND_HALF_UP') ? (string) PHP_ROUND_HALF_UP : 'n', "\n";
$p = (new ReflectionFunction('round'))->getParameters()[2];
echo 'type=', (string) $p->getType(), "\n";
echo 'const=', $p->isDefaultValueConstant() ? '1' : '0', "\n";
echo 'cname=', $p->getDefaultValueConstantName(), "\n";
$d = $p->getDefaultValue();
echo 'default=', $d instanceof RoundingMode ? $d->name : var_export($d, true), "\n";
--EXPECT--
backed=0
value=NULL
CEILING=n
FLOOR=n
TOWARD=n
AWAY=n
HALF_UP=1
type=RoundingMode|int
const=1
cname=RoundingMode::HalfAwayFromZero
default=HalfAwayFromZero
