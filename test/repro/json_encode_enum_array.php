<?php
error_reporting(E_ALL);
enum S: string { case A = 'a'; case B = 'b'; }
echo json_encode([S::A, S::B]) . "\n";
echo json_encode(['x' => S::A, 'y' => S::B]) . "\n";
echo json_encode(S::A) . "\n";
