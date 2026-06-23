<?php
$str = "über";
echo htmlentities($str, ENT_QUOTES, 'UTF-8'), "\n";
echo htmlspecialchars($str, ENT_QUOTES, 'UTF-8'), "\n";
