<?php
// #35852 — AOT iterator_to_array(SimpleXMLElement) hangs when Iterator host folds (#35844).
echo json_encode(iterator_to_array(new SimpleXMLElement('<r><a>1</a><b>2</b></r>')))."\n";
echo json_encode(iterator_to_array(new SimpleXMLElement('<r><a>1</a><b>2</b></r>'), false))."\n";
