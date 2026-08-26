<?php
// AOT: foreach-bound $fn() over string-literal arrays (#35075).
foreach (['strlen'] as $fn) {
    echo $fn('hi');
}
echo '|';
foreach (['abs', 'round', 'ceil', 'floor'] as $fn) {
    echo $fn(null), ',';
}
