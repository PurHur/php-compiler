<?php
// #35106 — AOT XMLReader::XML()+read() must not SIGSEGV on $reader->name.
$r = new XMLReader();
$r->XML('<r><a>1</a></r>');
while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT) {
        echo $r->name, '|';
    }
}
