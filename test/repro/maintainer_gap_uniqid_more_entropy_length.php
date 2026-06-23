<?php
for ($i = 0; $i < 5; $i++) {
    $u = uniqid('', true);
    echo strlen($u), ' ', $u, "\n";
}
