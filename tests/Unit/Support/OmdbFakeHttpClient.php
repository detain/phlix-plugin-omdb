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
 * Drives OmdbApi's real-HTTP paths without a network.
 *
 * The constructor deliberately skips the parent connection pool (unit tests
 * have no event loop). By default request() invokes the error callback
 * inline, mirroring the connection-error shape httpGetJson() handles. The
 * optional message template may embed the requested URL via the {url} token
 * to model a client exception that leaks the key-bearing URL into its own
 * message.
 *
 * `withBody()` models the SUCCESS arm instead: the response callback fires
 * inline with a real Workerman\Http\Response carrying the canned body,
 * exercising the decode-and-admit path no seam previously covered.
 */
final class OmdbFakeHttpClient extends Client
{
    /**
     * The URL the API asked to request, for request-path assertions.
     */
    public ?string $lastUrl = null;

    private ?string $successBody = null;

    /**
     * A fake whose request() fires NO callback ever — the transport accepted
     * the call but the response never arrives.
     */
    private bool $neverCompletes = false;

    public function __construct(private readonly ?string $messageTemplate = null)
    {
    }

    /**
     * Success-arm fake: every request completes inline with this body.
     */
    public static function withBody(string $body): self
    {
        $fake = new self();
        $fake->successBody = $body;

        return $fake;
    }

    /**
     * Silent-void fake: dispatch is accepted, nothing ever calls back.
     */
    public static function neverCompletes(): self
    {
        $fake = new self();
        $fake->neverCompletes = true;

        return $fake;
    }

    public function request($url, $options = [])
    {
        $this->lastUrl = (string) $url;

        if ($this->neverCompletes === true) {
            return;
        }

        if ($this->successBody !== null) {
            if (isset($options['success'])) {
                ($options['success'])(new \Workerman\Http\Response(200, [], $this->successBody));
            }

            return;
        }

        $template = $this->messageTemplate ?? 'Connection refused';
        $message = str_replace('{url}', (string) $url, $template);

        if (isset($options['error'])) {
            ($options['error'])(new \RuntimeException($message));
        }
    }
}
