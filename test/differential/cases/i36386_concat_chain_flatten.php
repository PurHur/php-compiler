<?php

// Concat-chain flatten into in-place .= (#36386)
$html = '';
for ($i = 0; $i < 30; ++$i) {
    $html .= '<tr><td>'.$i.'</td><td>x'.$i.'</td><td>'.($i * 2)."</td></tr>\n";
}
echo strlen($html), '|', substr($html, 0, 20), '|', md5($html), "\n";
