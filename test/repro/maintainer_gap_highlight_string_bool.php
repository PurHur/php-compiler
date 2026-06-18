<?php
$html = highlight_string('<?php echo 1; ?>', true);
echo 'type:' . gettype($html) . "\n";
echo 'len:' . strlen($html) . "\n";
echo 'has_span:' . (str_contains($html, '<span') ? 'yes' : 'no') . "\n";
