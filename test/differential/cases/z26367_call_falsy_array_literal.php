<?php

declare(strict_types=1);

// Call + array literal with false/true/null/[] — sibling call result must stay distinct (#26367).

function show($a, $b): void
{
    echo gettype($a), ':', json_encode($a), '|', gettype($b), ':', json_encode($b), "\n";
}

show(strtoupper('x'), ['k' => false]);
show(strtoupper('x'), ['k' => true]);
show(strtoupper('x'), ['k' => null]);
show(strtoupper('x'), ['k' => []]);
show(strtoupper('x'), ['k' => 0]);
