<?php
declare(strict_types=1);
$r = new ReflectionClass(stdClass::class);
foreach (['hasMethod', 'hasProperty', 'hasConstant'] as $m) {
    echo $m, '=', method_exists($r, $m) ? 'yes' : 'no', "\n";
}
echo 'hasMethod_toString=', $r->hasMethod('__toString') ? 'yes' : 'no', "\n";
