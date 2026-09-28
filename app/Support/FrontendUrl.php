<?php

namespace App\Support;

/** Builds absolute URLs to routes of the React app (used in e-mails). */
class FrontendUrl
{
    /** @param array<string, scalar> $query */
    public static function to(string $path, array $query = []): string
    {
        $base = rtrim((string) config('bora.frontend_url', config('app.url')), '/');
        $url = $base.'/'.ltrim($path, '/');

        return $query ? $url.'?'.http_build_query($query) : $url;
    }
}
