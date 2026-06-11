<?php
$ifaces = net_get_interfaces();
echo is_array($ifaces) ? "array\n" : "not_array\n";
