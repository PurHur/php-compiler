<?php
function show($a, $b) {
    echo "a=" . json_encode($a) . " alen=" . strlen($a)
        . " b=" . json_encode($b) . " blen=" . strlen($b) . "\n";
    return chop($a, $b);
}
function probe($Line) {
    $r = show(chop($Line['text'], ' '), $Line['text'][0]);
    echo "  result=" . json_encode($r) . "\n";
}
probe(['text' => '- li']);
probe(['text' => 'abc']);
probe(['text' => '---']);
