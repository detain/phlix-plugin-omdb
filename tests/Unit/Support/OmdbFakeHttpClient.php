<?php

/**
 * Synchronous-failure stand-in for the Workerman HTTP client.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\Omdb\Tests\Support;

use Workerman\Http\Client;

/**
 * Drives OmdbApi's real-HTTP error path without a network.
 *
 * The constructor deliberately skips the parent connection pool (unit tests
 * have no event loop) and request() invokes the error callback inline,
 * mirroring the connection-error shape httpGetJson() handles. The optional
 * message template may embed the requested URL via the {url} token to model
 * a client exception that leaks the key-bearing URL into its own message.
 */
final class OmdbFakeHttpClient extends Client
{
    /**
     * The URL the API asked to request, for request-path assertions.
     */
    public ?string $lastUrl = null;

    public function __construct(private readonly ?string $messageTemplate = null)
    {
    }

    public function request($url, $options = [])
    {
        $this->lastUrl = (string) $url;
        $template = $this->messageTemplate ?? 'Connection refused';
        $message = str_replace('{url}', (string) $url, $template);

        if (isset($options['error'])) {
            ($options['error'])(new \RuntimeException($message));
        }
    }
}
