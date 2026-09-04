<?php

declare(strict_types=1);

/**
 * Typed float return of a boxed accumulation must unwrap TYPE_NATIVE_DOUBLE
 * (#36386 / ScalarReturnCheck vs VmVariable::TYPE_FLOAT tag collision).
 */

final class BodyRet36386
{
    public function __construct(
        public float $x,
        public float $y,
        public float $mass,
    ) {
    }
}

function energy36386(array $bodies): float
{
    $e = 0.0;
    $n = count($bodies);
    for ($i = 0; $i < $n; ++$i) {
        /** @var BodyRet36386 $b */
        $b = $bodies[$i];
        $e += $b->mass;
        for ($j = $i + 1; $j < $n; ++$j) {
            /** @var BodyRet36386 $b2 */
            $b2 = $bodies[$j];
            $dx = $b->x - $b2->x;
            $dy = $b->y - $b2->y;
            $e -= ($b->mass * $b2->mass) / sqrt($dx * $dx + $dy * $dy);
        }
    }

    return $e;
}

$bodies = [
    new BodyRet36386(0.0, 0.0, 39.47841760435743),
    new BodyRet36386(4.84, -1.16, 0.037),
];
printf("%.9f\n", energy36386($bodies));
