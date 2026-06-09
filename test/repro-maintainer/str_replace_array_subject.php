<?php
declare(strict_types=1);

$subject = ['a1', 'b2'];
echo json_encode(str_replace('1', 'X', $subject)), "\n";
echo json_encode(str_ireplace('A', 'b', ['xA', 'yb'])), "\n";
