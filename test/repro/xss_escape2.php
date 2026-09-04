<?php
$s = 'https://www.example.com"';
echo '2arg=' . htmlspecialchars($s, ENT_QUOTES) . "\n";
echo '3arg=' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . "\n";
echo 'tern=' . htmlspecialchars($s, false ? ENT_NOQUOTES : ENT_QUOTES, 'UTF-8') . "\n";
echo 'noq=' . htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8') . "\n";
$flags = false ? ENT_NOQUOTES : ENT_QUOTES;
echo 'flags=' . $flags . ' via_var=' . htmlspecialchars($s, $flags, 'UTF-8') . "\n";
// Parsedown's exact escape
$text = $s;
$allowQuotes = false;
echo 'pd_exact=' . htmlspecialchars($text, $allowQuotes ? ENT_NOQUOTES : ENT_QUOTES, 'UTF-8') . "\n";
