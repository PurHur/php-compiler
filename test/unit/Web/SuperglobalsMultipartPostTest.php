<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Web\Superglobals;
use PHPUnit\Framework\TestCase;

/**
 * Issue #52: multipart/form-data POST populates $_POST and $_FILES (VM).
 */
final class SuperglobalsMultipartPostTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('REQUEST_METHOD');
        putenv('REQUEST_BODY');
        putenv('CONTENT_TYPE');
        putenv('HTTP_CONTENT_TYPE');
        parent::tearDown();
    }

    public function testMultipartTextAndFileFields(): void
    {
        $boundary = 'phpcTestBoundary';
        $body = implode("\r\n", [
            '--'.$boundary,
            'Content-Disposition: form-data; name="title"',
            '',
            'Hello Multipart',
            '--'.$boundary,
            'Content-Disposition: form-data; name="avatar"; filename="photo.txt"',
            'Content-Type: text/plain',
            '',
            'file-bytes',
            '--'.$boundary.'--',
            '',
        ]);

        putenv('REQUEST_METHOD=POST');
        putenv('REQUEST_BODY='.$body);
        putenv('CONTENT_TYPE=multipart/form-data; boundary='.$boundary);

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, '', null);

        $post = $runtime->vmContext->getSuperglobal('_POST');
        $this->assertNotNull($post);
        $this->assertSame(
            'Hello Multipart',
            $post->toArray()->find('title')->resolveIndirect()->toString()
        );

        $files = $runtime->vmContext->getSuperglobal('_FILES');
        $this->assertNotNull($files);
        $avatar = $files->toArray()->find('avatar')->resolveIndirect()->toArray();
        $this->assertSame('photo.txt', $avatar->find('name')->resolveIndirect()->toString());
        $this->assertSame('text/plain', $avatar->find('type')->resolveIndirect()->toString());
        $this->assertSame(0, $avatar->find('error')->resolveIndirect()->toInt());
        $this->assertSame(10, $avatar->find('size')->resolveIndirect()->toInt());
        $tmp = $avatar->find('tmp_name')->resolveIndirect()->toString();
        $this->assertFileExists($tmp);
        $this->assertSame('file-bytes', file_get_contents($tmp));
        @unlink($tmp);
    }
}
