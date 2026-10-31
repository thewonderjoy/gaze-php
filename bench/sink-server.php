<?php

// Minimal HTTP/1.1 keep-alive sink for bench/per-call.php (stand-in for the app, which keeps
// connections alive; `php -S` closes every connection, which would inflate client cost).
// Answers GET /v1/hello, POST /v1/batch (202) and GET /_stats (counts since the last /_stats).
// Usage: php bench/sink-server.php <port>

declare(strict_types=1);

$port = (int) ($argv[1] ?? 23611);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
stream_set_blocking($server, false);

$clients = [];
$buffers = [];
$stats = ['requests' => 0, 'entries' => 0, 'bytes' => 0, 'conns' => 0];

$hello = json_encode([
    'v' => 1,
    'server' => ['name' => 'bench-sink', 'version' => '0', 'instance' => bin2hex(random_bytes(4))],
    'protocol' => ['min' => 1, 'max' => 1],
    'features' => ['batch'],
    'limits' => ['max_body_bytes' => 67108864],
]);

$respond = static function ($conn, int $status, string $body): void {
    $reason = [200 => 'OK', 202 => 'Accepted', 404 => 'Not Found'][$status] ?? 'OK';
    fwrite($conn, "HTTP/1.1 $status $reason\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: keep-alive\r\n\r\n" . $body);
};

while (true) {
    $read = array_merge([$server], array_values($clients));
    $write = $except = null;
    if (@stream_select($read, $write, $except, 1) === false) {
        continue;
    }
    foreach ($read as $sock) {
        if ($sock === $server) {
            $conn = @stream_socket_accept($server, 0);
            if ($conn !== false) {
                stream_set_blocking($conn, false);
                $id = (int) $conn;
                $clients[$id] = $conn;
                $buffers[$id] = '';
                ++$stats['conns'];
            }
            continue;
        }
        $id = (int) $sock;
        $chunk = fread($sock, 1 << 20);
        if ($chunk === '' || $chunk === false) {
            if (feof($sock)) {
                fclose($sock);
                unset($clients[$id], $buffers[$id]);
            }
            continue;
        }
        $buffers[$id] .= $chunk;
        while (($end = strpos($buffers[$id], "\r\n\r\n")) !== false) {
            $head = substr($buffers[$id], 0, $end);
            $length = preg_match('/^content-length:\s*(\d+)/mi', $head, $m) ? (int) $m[1] : 0;
            if (strlen($buffers[$id]) < $end + 4 + $length) {
                break;
            }
            $body = substr($buffers[$id], $end + 4, $length);
            $buffers[$id] = (string) substr($buffers[$id], $end + 4 + $length);
            [$method, $path] = explode(' ', strtok($head, "\r\n")) + [1 => '/'];
            if ($method === 'GET' && $path === '/v1/hello') {
                $respond($sock, 200, (string) $hello);
            } elseif ($method === 'POST' && $path === '/v1/batch') {
                ++$stats['requests'];
                $stats['bytes'] += $length;
                $stats['entries'] += substr_count($body, '"seq":');
                $respond($sock, 202, '{"accepted":1}');
            } elseif ($path === '/_stats') {
                $body = (string) json_encode($stats);
                fwrite($sock, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
                fclose($sock);
                unset($clients[$id], $buffers[$id]);
                $stats = ['requests' => 0, 'entries' => 0, 'bytes' => 0, 'conns' => 0];
                break;
            } else {
                $respond($sock, 404, '{}');
            }
        }
    }
}
