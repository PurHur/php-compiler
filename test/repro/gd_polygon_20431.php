<?php
declare(strict_types=1);

/**
 * Repro for #20431 — imageopenpolygon vs imagepolygon closing edge.
 *
 * Note: php-src has no imageclosepolygon(); closed stroke is imagepolygon().
 */
foreach (['imageopenpolygon', 'imagepolygon', 'imageclosepolygon'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', PHP_EOL;
}

$bg = null;
$fg = null;

$open = imagecreatetruecolor(20, 20);
imagealphablending($open, false);
$bg = imagecolorallocate($open, 0, 0, 0);
$fg = imagecolorallocate($open, 255, 255, 255);
imagefilledrectangle($open, 0, 0, 19, 19, $bg);
// Right triangle: (2,2)-(16,2)-(2,16). Closing edge is (2,16)->(2,2) (x=2 vertical).
// Open must not draw that edge; closed must.
imageopenpolygon($open, [2, 2, 16, 2, 2, 16], $fg);
$openOnClose = imagecolorat($open, 2, 9) & 0xFFFFFF;
$openOnSide = imagecolorat($open, 9, 2) & 0xFFFFFF;

$closed = imagecreatetruecolor(20, 20);
imagealphablending($closed, false);
$bg = imagecolorallocate($closed, 0, 0, 0);
$fg = imagecolorallocate($closed, 255, 255, 255);
imagefilledrectangle($closed, 0, 0, 19, 19, $bg);
imagepolygon($closed, [2, 2, 16, 2, 2, 16], $fg);
$closedOnClose = imagecolorat($closed, 2, 9) & 0xFFFFFF;
$closedOnSide = imagecolorat($closed, 9, 2) & 0xFFFFFF;

echo 'open_no_close=', (0 === $openOnClose) ? 'yes' : 'no', PHP_EOL;
echo 'open_has_side=', (0 !== $openOnSide) ? 'yes' : 'no', PHP_EOL;
echo 'closed_has_close=', (0 !== $closedOnClose) ? 'yes' : 'no', PHP_EOL;
echo 'closed_has_side=', (0 !== $closedOnSide) ? 'yes' : 'no', PHP_EOL;
