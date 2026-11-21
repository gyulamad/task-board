<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Support;

use RuntimeException;

/**
 * A logged-in (or anonymous) user session against the running test server.
 * Handles CSRF tokens automatically for form posts.
 */
final class AppSession
{
    public HttpClient $http;
    private ?string $csrf = null;

    public function __construct(?string $username = null, ?string $password = null)
    {
        $this->http = new HttpClient(TestServer::url());
        if ($username !== null) {
            $this->login($username, $password ?? '');
        }
    }

    public function login(string $username, string $password): HttpResponse
    {
        $this->http->get('/?page=login'); // establishes the session and CSRF token
        return $this->post('/?page=login&i=1', ['username' => $username, 'password' => $password]);
    }

    /** Creates the first administrator through the real install flow on an empty database. */
    public function installAdmin(string $username, string $password, string $display = 'Administrator'): HttpResponse
    {
        $this->http->get('/?page=install');
        return $this->post('/?page=install', ['username' => $username, 'display_name' => $display, 'password' => $password]);
    }

    public function get(string $path): HttpResponse
    {
        return $this->http->get($path);
    }

    /** POSTs a form with the session CSRF token injected. */
    public function post(string $path, array $fields): HttpResponse
    {
        return $this->http->postForm($path, ['csrf' => $this->csrf()] + $fields);
    }

    /** POSTs a multipart form (file upload) with the session CSRF token injected. */
    public function upload(string $path, array $fields, string $fileField, string $filePath, string $filename, string $mime): HttpResponse
    {
        return $this->http->postMultipart($path, ['csrf' => $this->csrf()] + $fields, $fileField, $filePath, $filename, $mime);
    }

    public function postWithoutCsrf(string $path, array $fields): HttpResponse
    {
        return $this->http->postForm($path, $fields);
    }

    public function postWithCsrf(string $path, array $fields, string $csrf): HttpResponse
    {
        return $this->http->postForm($path, ['csrf' => $csrf] + $fields);
    }

    public function csrf(): string
    {
        if ($this->csrf !== null) {
            return $this->csrf;
        }
        $page = $this->http->get('/?page=login');
        if (!preg_match('/name="csrf" value="([0-9a-f]{64})"/', $page->body, $m)) {
            throw new RuntimeException('Could not extract a CSRF token from the response.');
        }
        return $this->csrf = $m[1];
    }

    public function logout(): HttpResponse
    {
        $response = $this->http->get('/?page=logout');
        $this->csrf = null;
        return $response;
    }

    /** Creates a task through the real form and returns its id. */
    public function createTask(string $title, array $overrides = []): int
    {
        $this->http->get('/?page=new');
        $response = $this->post('/?page=new', [
            'action' => 'save_task',
            'id' => '0',
            'title' => $title,
            'description' => $overrides['description'] ?? 'Created by the test suite',
            'status_id' => $overrides['status_id'] ?? '1',
            'parent_id' => $overrides['parent_id'] ?? '',
            'assignees' => (array)($overrides['assignees'] ?? []),
        ]);
        if (!preg_match('/page=task&id=(\d+)/', (string)$response->header('location'), $m)) {
            throw new RuntimeException('Task creation did not redirect to the task page. Status: ' . $response->status . ' Body: ' . substr($response->body, 0, 600));
        }
        return (int)$m[1];
    }

    public function taskPage(int $id): HttpResponse
    {
        return $this->http->get('/?page=task&id=' . $id);
    }
}
