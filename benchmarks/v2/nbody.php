<?php

declare(strict_types=1);

/**
 * N-body (scaled) — float arithmetic / array of structs shape (#36385).
 * Deterministic checksum of final energy.
 */

final class Body
{
    public function __construct(
        public float $x,
        public float $y,
        public float $z,
        public float $vx,
        public float $vy,
        public float $vz,
        public float $mass,
    ) {
    }
}

function energy(array $bodies): float
{
    $e = 0.0;
    $n = count($bodies);
    for ($i = 0; $i < $n; ++$i) {
        /** @var Body $b */
        $b = $bodies[$i];
        $e += 0.5 * $b->mass * ($b->vx * $b->vx + $b->vy * $b->vy + $b->vz * $b->vz);
        for ($j = $i + 1; $j < $n; ++$j) {
            /** @var Body $b2 */
            $b2 = $bodies[$j];
            $dx = $b->x - $b2->x;
            $dy = $b->y - $b2->y;
            $dz = $b->z - $b2->z;
            $e -= ($b->mass * $b2->mass) / sqrt($dx * $dx + $dy * $dy + $dz * $dz);
        }
    }

    return $e;
}

function advance(array $bodies, float $dt): void
{
    $n = count($bodies);
    for ($i = 0; $i < $n; ++$i) {
        /** @var Body $b */
        $b = $bodies[$i];
        for ($j = $i + 1; $j < $n; ++$j) {
            /** @var Body $b2 */
            $b2 = $bodies[$j];
            $dx = $b->x - $b2->x;
            $dy = $b->y - $b2->y;
            $dz = $b->z - $b2->z;
            $dist = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
            $mag = $dt / ($dist * $dist * $dist);
            $b->vx -= $dx * $b2->mass * $mag;
            $b->vy -= $dy * $b2->mass * $mag;
            $b->vz -= $dz * $b2->mass * $mag;
            $b2->vx += $dx * $b->mass * $mag;
            $b2->vy += $dy * $b->mass * $mag;
            $b2->vz += $dz * $b->mass * $mag;
        }
    }
    foreach ($bodies as $b) {
        $b->x += $dt * $b->vx;
        $b->y += $dt * $b->vy;
        $b->z += $dt * $b->vz;
    }
}

$pi = 3.141592653589793;
$solarMass = 4.0 * $pi * $pi;
$daysPerYear = 365.24;

$bodies = [
    new Body(0.0, 0.0, 0.0, 0.0, 0.0, 0.0, $solarMass),
    new Body(
        4.84143144246472090e+00,
        -1.16032004402742839e+00,
        -1.03622044471123109e-01,
        1.66007664274403694e-03 * $daysPerYear,
        7.69901118419740425e-03 * $daysPerYear,
        -6.90460016972063023e-05 * $daysPerYear,
        9.54791938424326609e-04 * $solarMass
    ),
    new Body(
        8.34336671824457987e+00,
        4.12479856412430479e+00,
        -4.03523417114321381e-01,
        -2.76742510726862411e-03 * $daysPerYear,
        4.99852801234917238e-03 * $daysPerYear,
        2.30417297573763929e-05 * $daysPerYear,
        2.85885980666130812e-04 * $solarMass
    ),
    new Body(
        1.28943695621391310e+01,
        -1.51111514016986312e+01,
        -2.23307578892655734e-01,
        2.96460137564761618e-03 * $daysPerYear,
        2.37847173977393856e-03 * $daysPerYear,
        -2.96589568540237556e-05 * $daysPerYear,
        4.36624404335156298e-05 * $solarMass
    ),
    new Body(
        1.53796971148509165e+01,
        -2.59193146099879641e+01,
        1.79258772950371181e-01,
        2.68067772490389322e-03 * $daysPerYear,
        1.62824170038242295e-03 * $daysPerYear,
        -9.51592254519715870e-05 * $daysPerYear,
        5.15138902046611451e-05 * $solarMass
    ),
];

$px = 0.0;
$py = 0.0;
$pz = 0.0;
foreach ($bodies as $b) {
    $px += $b->vx * $b->mass;
    $py += $b->vy * $b->mass;
    $pz += $b->vz * $b->mass;
}
$bodies[0]->vx = -$px / $solarMass;
$bodies[0]->vy = -$py / $solarMass;
$bodies[0]->vz = -$pz / $solarMass;

printf("%.9f\n", energy($bodies));
for ($i = 0; $i < 500; ++$i) {
    advance($bodies, 0.01);
}
printf("%.9f\n", energy($bodies));
