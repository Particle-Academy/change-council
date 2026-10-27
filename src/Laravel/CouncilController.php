<?php

declare(strict_types=1);

namespace ChangeCouncil\Laravel;

use ChangeCouncil\Http\Api;
use ChangeCouncil\Http\ApiResponse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The thin Laravel adapter over {@see Api}: size limit, JSON decoding and the
 * authenticated user. Nothing else lives here.
 */
final class CouncilController
{
    public function __construct(private readonly Api $api, private readonly Repository $config) {}

    public function changes(Request $request): JsonResponse
    {
        return self::json($this->api->changes($request->user(), $request->query()));
    }

    public function change(Request $request, string $ref): JsonResponse
    {
        return self::json($this->api->change($request->user(), $ref));
    }

    public function rounds(Request $request, string $ref): JsonResponse
    {
        return self::json($this->api->rounds($request->user(), $ref));
    }

    public function consumers(Request $request): JsonResponse
    {
        return self::json($this->api->consumers($request->user()));
    }

    public function feed(Request $request): JsonResponse
    {
        $since = filter_var($request->query('since', '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($since === false) {
            return self::json(Api::error(422, 'invalid_request'));
        }

        return self::json($this->api->feed($request->user(), $since));
    }

    public function approveInitiation(Request $request, string $ref): JsonResponse
    {
        return $this->withBody($request, fn (?array $body) => $this->api->approveInitiation($request->user(), $ref, $body));
    }

    public function rejectInitiation(Request $request, string $ref): JsonResponse
    {
        return $this->withBody($request, fn (?array $body) => $this->api->rejectInitiation($request->user(), $ref, $body));
    }

    public function override(Request $request, string $ref): JsonResponse
    {
        return $this->withBody($request, fn (?array $body) => $this->api->override($request->user(), $ref, $body));
    }

    public function revoke(Request $request, string $ref): JsonResponse
    {
        return $this->withBody($request, fn (?array $body) => $this->api->revoke($request->user(), $ref, $body));
    }

    public function changeSilencePolicy(Request $request, string $ref): JsonResponse
    {
        return $this->withBody($request, fn (?array $body) => $this->api->changeSilencePolicy($request->user(), $ref, $body));
    }

    public function consumerSilencePolicy(Request $request, string $address): JsonResponse
    {
        return $this->withBody($request, fn (?array $body) => $this->api->consumerSilencePolicy($request->user(), $address, $body));
    }

    private function withBody(Request $request, \Closure $call): JsonResponse
    {
        $content = (string) $request->getContent();
        if (strlen($content) > (int) $this->config->get('council.routes.max_body_bytes', 65536)) {
            return self::json(Api::error(413, 'payload_too_large'));
        }
        $body = [];
        if (trim($content) !== '') {
            $decoded = json_decode($content, true, 32);
            $body = is_array($decoded) && (! array_is_list($decoded) || $decoded === []) ? $decoded : null;
        }

        return self::json($call($body));
    }

    private static function json(ApiResponse $response): JsonResponse
    {
        return new JsonResponse($response->body, $response->status, [], ApiResponse::FLAGS & ~JSON_THROW_ON_ERROR);
    }
}
