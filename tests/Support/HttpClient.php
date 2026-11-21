<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Support;

/**
 * Minimal curl based HTTP client for exercising the task board over HTTP.
 * Redirects are NOT followed automatically so tests can assert Location headers.
 */
final class HttpClient
{
    private string $base;
    private string $cookieFile;

    public function __construct(string $base)
    {
        $this->base = rtrim($base, '/');
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'tb-cookies-');
    }

    public function get(string $path): HttpResponse
    {
        return $this->request('GET', $path, null, []);
    }

    public function postForm(string $path, array $fields): HttpResponse
    {
        return $this->request('POST', $path, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
    }

    public function postMultipart(string $path, array $fields, string $fileField, string $filePath, string $filename, string $mime): HttpResponse
    {
        $curlFile = new \CURLFile($filePath, $mime, $filename);
        return $this->request('POST', $path, null, [], compact('fields', 'fileField', 'curlFile'));
    }

    private function request(string $method, string $path, ?string $body, array $headers, array $multipart = []): HttpResponse
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($multipart !== []) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart['fields'] + [$multipart['fileField'] => $multipart['curlFile']]);
        } elseif ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP request to {$path} failed: {$error}");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr((string)$raw, 0, $headerSize);
        $responseHeaders = [];
        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))][] = trim($value);
            }
        }
        return new HttpResponse($status, substr((string)$raw, $headerSize), $responseHeaders);
    }
}
