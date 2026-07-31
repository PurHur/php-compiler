--TEST--
FCC / Closure::fromCallable missing method → __call / __callStatic (#25757)
--FILE--
<?php
class FccMagicCall {
    public function __call($n, $a) {
        return 'call:' . $n . ':' . implode(',', $a);
    }
    public static function __callStatic($n, $a) {
        return 'cs:' . $n . ':' . implode(',', $a);
    }
}
$a = new FccMagicCall;
$c = $a->missing(...);
echo $c('x', 'y'), "\n";
$c = FccMagicCall::missing(...);
echo $c('z'), "\n";
echo Closure::fromCallable([$a, 'missing'])('q'), "\n";
echo Closure::fromCallable([FccMagicCall::class, 'missing'])('w'), "\n";
--EXPECT--
call:missing:x,y
cs:missing:z
call:missing:q
cs:missing:w
