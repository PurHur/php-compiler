<?php

declare(strict_types=1);

/**
 * Template render — row formatting / string building (#36385).
 */

$rows = 8000;
$html = '';
$count = 0;
for ($i = 0; $i < $rows; ++$i) {
    $html .= '<tr><td>'.$i.'</td><td>name-'.$i.'</td><td>'.($i * 3)."</td></tr>\n";
    ++$count;
}

echo strlen($html), '|', $count, "\n";
