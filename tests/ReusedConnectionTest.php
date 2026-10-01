<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sendly\Exceptions\NetworkException;
use Sendly\Exceptions\SendlyException;
use Sendly\Sendly;

class ReusedConnectionTest extends TestCase
{
    private const SENDER = '+15555550147';
    private const PHOTO_PATH = '/api/v1/whatsapp/senders/%2B15555550147/profile/photo';

    private const SERVER = <<<'PHP'
        <?php
        $log = $argv[1];
        $answer = $argv[2] ?? '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) {
            fwrite(STDERR, "test server: {$error}\n");
            exit(1);
        }
        $name = stream_socket_get_name($server, false);
        echo substr($name, strrpos($name, ':') + 1), "\n";
        $connections = [];
        $buffers = [];
        $numbers = [];
        $accepted = 0;
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline) {
            $read = array_merge([$server], array_values($connections));
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 1) < 1) {
                continue;
            }
            foreach ($read as $socket) {
                if ($socket === $server) {
                    $connection = stream_socket_accept($server);
                    stream_set_read_buffer($connection, 0);
                    $connections[(int) $connection] = $connection;
                    $buffers[(int) $connection] = '';
                    $numbers[(int) $connection] = ++$accepted;
                    continue;
                }
                $id = (int) $socket;
                $chunk = fread($socket, 65536);
                if ($chunk === false || $chunk === '') {
                    fclose($socket);
                    unset($connections[$id], $buffers[$id]);
                    continue;
                }
                $buffers[$id] .= $chunk;
                while (($end = strpos($buffers[$id], "\r\n\r\n")) !== false) {
                    $head = substr($buffers[$id], 0, $end);
                    $length = preg_match('/^content-length:\s*(\d+)/mi', $head, $match) === 1 ? (int) $match[1] : 0;
                    if (strlen($buffers[$id]) < $end + 4 + $length) {
                        break;
                    }
                    $buffers[$id] = substr($buffers[$id], $end + 4 + $length);
                    [$method, $target] = explode(' ', $head, 3);
                    file_put_contents($log, "{$numbers[$id]} {$method} {$target}\n", FILE_APPEND);
                    if ($method === 'GET') {
                        $json = '{"senders":[]}';
                        fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: keep-alive\r\n\r\n{$json}");
                        continue;
                    }
                    if ($answer !== '') {
                        fwrite($socket, "HTTP/1.1 {$answer}\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
                    }
                    fclose($socket);
                    unset($connections[$id], $buffers[$id]);
                    break;
                }
            }
        }
        PHP;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $log = '';

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sendly-reuse-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function clientOnAWarmConnection(string $answer = ''): Sendly
    {
        $script = $this->tempFile(self::SERVER);
        $this->log = $this->tempFile('');
        $this->server = proc_open([PHP_BINARY, $script, $this->log, $answer], [1 => ['pipe', 'w']], $this->pipes);
        $port = (int) fgets($this->pipes[1]);
        $this->assertGreaterThan(0, $port, 'the test server did not start');

        $client = new Sendly('test_api_key', [
            'baseUrl' => "http://127.0.0.1:{$port}/api/v1",
            'maxRetries' => 0,
            'timeout' => 5,
        ]);
        $client->whatsapp()->senders->list();

        return $client;
    }

    /**
     * @return array<int, string>
     */
    private function requestsReceived(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->log))));
    }

    /**
     * @return array<int, string>
     */
    private function postsReceived(): array
    {
        return array_values(array_filter(
            $this->requestsReceived(),
            fn(string $line) => str_contains($line, ' POST ')
        ));
    }

    private function assertSentOnceOnAFreshConnection(string $path, callable $call): void
    {
        $client = $this->clientOnAWarmConnection();

        try {
            $call($client);
            $this->fail('Expected NetworkException');
        } catch (NetworkException $e) {
        }

        $this->assertSame(['1 GET /api/v1/whatsapp/senders', "2 POST {$path}"], $this->requestsReceived());
    }

    public function testVerifyIsSentOnceWhenTheConnectionDropsAfterTheRequest(): void
    {
        $this->assertSentOnceOnAFreshConnection(
            '/api/v1/whatsapp/signup/was_add_1/verify',
            fn(Sendly $client) => $client->whatsapp()->signup->verify('was_add_1', '481516')
        );
    }

    public function testAddByCodeSignupIsSentOnceWhenTheConnectionDropsAfterTheRequest(): void
    {
        $this->assertSentOnceOnAFreshConnection(
            '/api/v1/whatsapp/signup',
            fn(Sendly $client) => $client->whatsapp()->signup->create(self::SENDER, ['businessAccountId' => '104996582519384'])
        );
    }

    public function testProfilePhotoUploadIsSentOnceWhenTheConnectionDropsAfterTheRequest(): void
    {
        $photo = $this->tempFile("\x89PNG\r\n\x1a\n" . str_repeat("\0", 32));

        $this->assertSentOnceOnAFreshConnection(
            self::PHOTO_PATH,
            fn(Sendly $client) => $client->whatsapp()->senders->uploadProfilePhoto(self::SENDER, $photo)
        );
    }

    public function testLargeProfilePhotoUploadIsSentOnceWhenTheConnectionDropsAfterTheRequest(): void
    {
        $photo = $this->tempFile("\x89PNG\r\n\x1a\n" . str_repeat("\0", 2 * 1024 * 1024));

        $this->assertSentOnceOnAFreshConnection(
            self::PHOTO_PATH,
            fn(Sendly $client) => $client->whatsapp()->senders->uploadProfilePhoto(self::SENDER, $photo)
        );
    }

    public function testVerifyAnsweredWithA408IsSentOnce(): void
    {
        $client = $this->clientOnAWarmConnection('408 Request Timeout');

        try {
            $client->whatsapp()->signup->verify('was_add_1', '481516');
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(408, $e->getCode());
        }

        $this->assertCount(1, $this->postsReceived());
    }

    /**
     * @return array<string, array{string, callable(Sendly): mixed}>
     */
    public static function callsThatKeepTheirConnection(): array
    {
        return [
            'resend' => [
                '/api/v1/whatsapp/signup/was_add_1/resend',
                fn(Sendly $client) => $client->whatsapp()->signup->resend('was_add_1', 'voice'),
            ],
            'Facebook signup' => [
                '/api/v1/whatsapp/signup',
                fn(Sendly $client) => $client->whatsapp()->signup->create(self::SENDER),
            ],
            'post()' => [
                '/api/v1/short_codes/application/submit',
                fn(Sendly $client) => $client->post('/short_codes/application/submit'),
            ],
            'postMultipart()' => [
                '/api/v1/media',
                fn(Sendly $client) => $client->postMultipart('/media', [['name' => 'file', 'contents' => 'x', 'filename' => 'a.png']]),
            ],
        ];
    }

    #[DataProvider('callsThatKeepTheirConnection')]
    public function testOtherCallsStillReuseTheOpenConnection(string $path, callable $call): void
    {
        $client = $this->clientOnAWarmConnection();

        try {
            $call($client);
            $this->fail('Expected NetworkException');
        } catch (NetworkException $e) {
        }

        $this->assertSame("1 POST {$path}", $this->postsReceived()[0] ?? null);
    }
}
