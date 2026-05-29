<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\FastCgi\ParamsCodec;
use PHPCompiler\Web\FastCgi\Record;

/**
 * FastCGI record + params codec (issue #173 slice 1).
 */
final class FastCgiRecordTest extends TestCase
{
    public function testBeginRequestRecordRoundTrip(): void
    {
        $raw = Record::encodeBeginRequest(42, Record::ROLE_RESPONDER, 0);
        $this->assertSame(16, strlen($raw));
        $records = Record::decodeAll($raw);
        $this->assertCount(1, $records);
        $this->assertSame(Record::BEGIN_REQUEST, $records[0]['type']);
        $this->assertSame(42, $records[0]['requestId']);
        $this->assertSame(8, strlen($records[0]['content']));
        $role = (ord($records[0]['content'][0]) << 8) | ord($records[0]['content'][1]);
        $this->assertSame(Record::ROLE_RESPONDER, $role);
    }

    public function testParamsCodecRoundTrip(): void
    {
        $params = [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_FILENAME' => '/var/www/example.php',
            'QUERY_STRING' => 'a=1',
        ];
        $encoded = ParamsCodec::encode($params);
        $this->assertSame($params, ParamsCodec::decode($encoded));
    }

    public function testParamsCodecLongNameValue(): void
    {
        $long = str_repeat('x', 200);
        $params = ['KEY_'.$long => 'VAL_'.$long];
        $this->assertSame($params, ParamsCodec::decode(ParamsCodec::encode($params)));
    }

    public function testStdoutPaddingAndChunks(): void
    {
        $payload = str_repeat('a', 9);
        $single = Record::encodeStdout(1, $payload);
        $this->assertSame(8 + 9 + 7, strlen($single));
        $records = Record::decodeAll($single);
        $this->assertSame($payload, $records[0]['content']);

        $big = str_repeat('b', 70000);
        $chunks = Record::encodeStdoutChunks(3, $big);
        $this->assertGreaterThan(1, count($chunks));
        $reassembled = '';
        foreach ($chunks as $chunk) {
            foreach (Record::decodeAll($chunk) as $rec) {
                $this->assertSame(Record::STDOUT, $rec['type']);
                $reassembled .= $rec['content'];
            }
        }
        $this->assertSame($big, $reassembled);
    }

    public function testEndRequestBody(): void
    {
        $raw = Record::encodeEndRequest(5, 0, Record::PROTOCOL_STATUS_REQUEST_COMPLETE);
        $records = Record::decodeAll($raw);
        $this->assertSame(Record::END_REQUEST, $records[0]['type']);
        $unpacked = unpack('Napp/Cproto', $records[0]['content']);
        $this->assertSame(0, $unpacked['app']);
        $this->assertSame(Record::PROTOCOL_STATUS_REQUEST_COMPLETE, $unpacked['proto']);
    }

    public function testDecodeAllMultipleRecords(): void
    {
        $buffer = Record::encodeBeginRequest(1, Record::ROLE_RESPONDER, 0)
            .Record::encodeParams(1, ParamsCodec::encode(['REQUEST_METHOD' => 'GET']))
            .Record::encodeParams(1, '')
            .Record::encodeStdin(1, '');
        $records = Record::decodeAll($buffer);
        $this->assertCount(4, $records);
        $this->assertSame(Record::BEGIN_REQUEST, $records[0]['type']);
        $this->assertSame(Record::PARAMS, $records[1]['type']);
        $this->assertSame(Record::PARAMS, $records[2]['type']);
        $this->assertSame(Record::STDIN, $records[3]['type']);
    }

    public function testRejectUnsupportedVersion(): void
    {
        $bad = chr(2).chr(Record::BEGIN_REQUEST).pack('nnCC', 1, 8, 0, 0).str_repeat("\0", 8);
        $this->expectException(\InvalidArgumentException::class);
        Record::decodeOne($bad);
    }
}
