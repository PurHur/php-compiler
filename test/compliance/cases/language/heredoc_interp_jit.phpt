--TEST--
Language: heredoc interpolation JIT (issue #178)
--FILE--
<?php
$name = 'world';
echo <<<HTML
<div>{$name}</div>
HTML;
--EXPECT--
<div>world</div>
