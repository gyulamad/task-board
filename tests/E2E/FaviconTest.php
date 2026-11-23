<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;

/**
 * End-to-end coverage for the favicon (#60): every page advertises the icon
 * in its head and both icon files are served from the document root, so the
 * browser tab actually shows the task board mark.
 */
final class FaviconTest extends E2eTestCase
{
    public function test_pages_link_the_favicon(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?');
        $this->assertSame(200, $page->status);
        // The SVG is offered first (crisp on modern browsers) and the ICO is
        // the fallback with the exact sizes the file carries.
        $this->assertTrue($page->contains('<link rel="icon" type="image/svg+xml" href="favicon.svg">'));
        $this->assertTrue($page->contains('<link rel="icon" href="favicon.ico" sizes="48x48 32x32 16x16">'));
    }

    public function test_the_favicon_files_are_served(): void
    {
        $ico = TestServer::projectRoot() . '/favicon.ico';
        $svg = TestServer::projectRoot() . '/favicon.svg';
        $this->assertFileExists($ico);
        $this->assertFileExists($svg);

        $response = $this->session->get('/favicon.ico');
        $this->assertSame(200, $response->status, 'The ICO must be served statically from the document root.');
        $this->assertSame(file_get_contents($ico), $response->body, 'The served ICO must be byte-identical to the repository file.');

        $response = $this->session->get('/favicon.svg');
        $this->assertSame(200, $response->status, 'The SVG must be served statically from the document root.');
        $this->assertSame(file_get_contents($svg), $response->body, 'The served SVG must be byte-identical to the repository file.');
    }

    public function test_the_ico_carries_the_advertised_sizes(): void
    {
        $ico = TestServer::projectRoot() . '/favicon.ico';
        // ICO directory: 6-byte reserved+type header, then one 16-byte entry
        // per image whose width/height byte is 0 for 256 and the pixel size
        // otherwise. The file advertises 16, 32 and 48.
        $header = file_get_contents($ico, false, null, 0, 6 + 16 * 3);
        $this->assertNotFalse($header);
        $count = unpack('v', substr($header, 4, 2))[1];
        $this->assertSame(3, $count, 'The ICO must bundle exactly the three advertised sizes.');
        $sizes = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = unpack('Cwidth/Cheight', substr($header, 6 + 16 * $i, 2));
            $sizes[] = [$entry['width'], $entry['height']];
        }
        $this->assertEqualsCanonicalizing([[16, 16], [32, 32], [48, 48]], $sizes);
    }
}
