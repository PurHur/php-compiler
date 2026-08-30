<?php
echo json_encode((new SimpleXMLElement('<root><a/></root>'))->hasChildren());
echo "\n";
