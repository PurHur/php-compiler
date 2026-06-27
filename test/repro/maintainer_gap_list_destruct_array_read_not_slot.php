<?php

declare(strict_types=1);

/**
 * Regression for #12602 — array read temps must not match list-destruct dim-fetch shape.
 *
 * bin/compile.php bundles src/cli.php and uses $options['-y'] reads; #12498 writable-slot
 * validation misclassified those SSA assigns as destructuring slots and failed compile.
 */
$options = ['-y' => true, '-o' => 'out.php'];
$debugFile = true === $options['-y'] ? $options['-o'] : $options['-y'];
echo $debugFile, "\n";
