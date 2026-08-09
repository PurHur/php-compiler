<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';

use PHPCompiler\Ast\CloneWithDesugar;

// Keyword `with` forms must not desugar (#29187); only parenthesized clone($obj, [...]).
$cases = [
    'clone $c with ["a" => 2];' => false,
    'clone($c, ["a" => 2]);' => true,
    '(clone $c) with ["a" => 2];' => false,
];

$failed = 0;
foreach ($cases as $snippet => $shouldRewrite) {
    $input = '<?php $d = '.$snippet;
    $output = CloneWithDesugar::desugar($input);
    $rewritten = $output !== $input;
    if ($rewritten !== $shouldRewrite) {
        echo "FAIL: {$snippet}\n  input:  {$input}\n  output: {$output}\n";
        ++$failed;
    } else {
        echo "OK: {$snippet}\n";
    }
}

exit($failed > 0 ? 1 : 0);
