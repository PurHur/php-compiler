<?php
// #34639 — AOT password_get_info must not return empty HT after bcrypt hash
$h = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
$i = password_get_info($h);
echo $i['algo'], "\n";
echo $i['algoName'], "\n";
echo $i['options']['cost'], "\n";
echo json_encode($i), "\n";
