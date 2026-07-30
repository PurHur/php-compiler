<?php
/**
 * #25063 — reference profile highlight wire matches host Zend 8.2
 * (<code><span>, &nbsp;, no <pre>). Forward PROFILE=8.4 keeps <pre><code> (#24874).
 */
$z = highlight_string('<?php echo 1;', true);
echo 'nbsp=', (strpos($z, '&nbsp;') !== false ? '1' : '0'), "\n";
echo 'pre=', (strpos($z, '<pre>') !== false ? '1' : '0'), "\n";
echo 'code_span=', (preg_match('/<code><span/', $z) ? '1' : '0'), "\n";
