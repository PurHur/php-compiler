<?php
/**
 * #24896 AOT — unpack named/omitted offset (Reflection under AOT is a pre-existing gap).
 */
echo json_encode(unpack(format: 'C*', string: 'AB', offset: 1)), "\n";
echo json_encode(unpack('C*', 'AB')), "\n";
