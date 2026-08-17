<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

/**
 * RFC 9457 errors (§17).
 *
 * Partners integrate against `type`, so the type strings are part of the
 * contract and must never change — the human `title` can be reworded
 * freely, the URI cannot. That split is the whole point of the format:
 * one field for people, one for programs.
 */
final class Problem
{
    public const BASE = 'https://docs.doba.dev/problems/';

    /**
     * @param  array<string,mixed>  $extra
     */
    public static function make(string $type, string $title, int $status, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'type' => self::BASE.$type,
            'title' => $title,
            'status' => $status,
        ], $extra), $status, [
            'Content-Type' => 'application/problem+json',
        ]);
    }

    public static function unauthorized(string $title = 'Missing or invalid API credentials.'): JsonResponse
    {
        return self::make('unauthorized', $title, 401);
    }

    public static function forbidden(string $title): JsonResponse
    {
        return self::make('forbidden', $title, 403);
    }

    public static function notFound(string $title = 'No such resource.'): JsonResponse
    {
        return self::make('not-found', $title, 404);
    }

    /**
     * @param  array<string,array<int,string>>  $errors
     */
    public static function validation(array $errors): JsonResponse
    {
        return self::make('validation-failed', 'The request body is not valid.', 422, [
            'errors' => $errors,
        ]);
    }

    public static function conflict(string $type, string $title): JsonResponse
    {
        return self::make($type, $title, 409);
    }

    public static function unavailable(string $date): JsonResponse
    {
        // Its own type rather than a generic 409: "the room went while you
        // were deciding" is the one failure a booking partner must handle
        // specifically, and telling it apart from a duplicate idempotency
        // key by reading the title is not an integration anyone should
        // have to write.
        return self::make('no-availability', 'That stay is no longer available.', 409, [
            'date' => $date,
        ]);
    }
}
