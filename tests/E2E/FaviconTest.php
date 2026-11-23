<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;

/**
 * End-to-end coverage for the favicon (#60): every page advertises the icon
 * in its head and the icon is served through the app itself (?page=favicon),
 * so hosts that mishandle static file requests cannot 404 it into their error
 * page. The files also stay at the document root for servers that serve them
 * statically; the routes read exactly those bytes.
 */
final class FaviconTest extends E2eTestCase
{
    public function test_pages_link_the_favicon_routes(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?');
        $this->assertSame(200, $page->status);
        // The SVG is offered first (crisp on modern browsers) and the ICO is
        // the fallback with the exact sizes the file carries.
        $this->assertTrue($page->contains('<link rel="icon" type="image/svg+xml" href="?page=favicon&amp;type=svg">'));
        $this->assertTrue($page->contains('<link rel="icon" href="?page=favicon" sizes="48x48 32x32 16x16">'));
    }

    public function test_the_favicon_routes_serve_the_icon_files(): void
    {
        $ico = file_get_contents(TestServer::docRoot() . '/favicon.ico');
        $svg = file_get_contents(TestServer::docRoot() . '/favicon.svg');
        $this->assertNotFalse($ico);
        $this->assertNotFalse($svg);

        $response = $this->session->get('/?page=favicon');
        $this->assertSame(200, $response->status, 'The ICO route must answer on every board.');
        $this->assertSame('image/x-icon', (string)$response->header('content-type'));
        $this->assertSame($ico, $response->body, 'The route must serve the ICO byte-identical to the repository file.');

        $response = $this->session->get('/?page=favicon&type=svg');
        $this->assertSame(200, $response->status, 'The SVG route must answer on every board.');
        $this->assertSame('image/svg+xml', (string)$response->header('content-type'));
        $this->assertSame($svg, $response->body, 'The route must serve the SVG byte-identical to the repository file.');
    }

    public function test_the_favicon_routes_answer_to_anonymous_visitors(): void
    {
        // The sign-in page carries the icon links too, so the routes cannot
        // sit behind the login guard.
        $response = $this->session->get('/?page=favicon');
        $this->assertSame(200, $response->status);
        $this->assertSame('image/x-icon', (string)$response->header('content-type'));
    }

    public function test_the_ico_carries_the_advertised_sizes(): void
    {
        $ico = TestServer::docRoot() . '/favicon.ico';
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
