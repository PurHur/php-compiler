<?php
echo json_encode((object)[]), "\n";
echo json_encode(new stdClass()), "\n";
class C { public int $x = 1; }
echo json_encode(new C()), "\n";
