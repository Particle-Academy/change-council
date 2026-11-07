<?php

declare(strict_types=1);

namespace ChangeCouncil\Http;

/** A framework-neutral answer: a status and a JSON-encodable body. */
final readonly class ApiResponse
{
    // HEX_TAG/HEX_AMP: agent-supplied text (a reply, a member's project) goes
    // out with < > & escaped, so the body is inert even if something serves it
    // as HTML. Decoders read the same strings.
    public const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP;

    public function __construct(public int $status, public array|object $body) {}

    public function json(): string
    {
        return json_encode($this->body, self::FLAGS);
    }
}
