<?php

/**
 * One-shot local HTTPS fixture for the real cURL transport tests.
 *
 * Usage: php OmdbTlsFixtureServer.php <pem> <mode>
 * Prints the listening port on its first stdout line, serves exactly one
 * request, then exits. Modes: ok | declared-oversize | chunked-oversize.
 */

declare(strict_types=1);

[, $pem, $mode] = $argv;
$ctx = stream_context_create(['ssl' => [
    'local_cert' => $pem,
    'allow_self_signed' => true,
    'verify_peer' => false,
]]);
$server = stream_socket_server('ssl://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
echo substr($name, (int) strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

$conn = @stream_socket_accept($server, 15);
if ($conn === false) {
    exit(2);
}
while (($line = fgets($conn)) !== false && rtrim($line) !== '') {
    // drain request headers
}

$over = 5 * 1024 * 1024; // above the 4 MiB cap
if ($mode === 'ok') {
    $body = '{"Response":"True","Title":"Inception"}';
    @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
} elseif ($mode === 'declared-oversize') {
    @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: $over\r\nConnection: close\r\n\r\n" . str_repeat('a', 1024));
    usleep(500000);
} else {
    @fwrite($conn, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
    $chunk = str_repeat('a', 65536);
    for ($sent = 0; $sent < $over; $sent += 65536) {
        if (@fwrite($conn, dechex(65536) . "\r\n" . $chunk . "\r\n") === false) {
            break;
        }
    }
    @fwrite($conn, "0\r\n\r\n");
}
@fclose($conn);
