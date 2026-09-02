<?php
// #36353 — sprintf with count() twice after foreach (dead-temp + Div between siblings)
$users = [["score" => 98.5], ["score" => 100.0], ["score" => 91.25]];
$sum = 0.0;
foreach ($users as $u) {
    $sum += $u["score"];
}
echo sprintf("n=%d avg=%.3f", count($users), $sum / count($users)), "\n";
