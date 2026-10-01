<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a private file (after the caller's authorization check) with
 * browser caching: Cache-Control, ETag and Last-Modified, and a 304 when the
 * browser already has this exact file.
 */
trait ServesStoredFiles
{
    /** @param array<string, string> $headers */
    protected function cachedFile(Request $request, Filesystem $disk, string $path, string $cacheControl, array $headers = []): Response
    {
        $modified = $disk->lastModified($path);
        // Stored file names are random and never reused: path + time + size identify the content.
        $etag = '"'.substr(md5($path.'|'.$modified.'|'.$disk->size($path)), 0, 20).'"';

        $probe = new Response;
        $probe->setEtag($etag);
        $probe->setLastModified(Carbon::createFromTimestamp($modified));
        $probe->headers->set('Cache-Control', $cacheControl);

        if ($probe->isNotModified($request)) {
            $probe->headers->set('X-Content-Type-Options', 'nosniff');

            return $probe;
        }

        $response = $disk->response($path, null, $headers + ['X-Content-Type-Options' => 'nosniff']);
        $response->headers->set('Cache-Control', $cacheControl);
        $response->setEtag($etag);
        $response->setLastModified(Carbon::createFromTimestamp($modified));

        return $response;
    }
}
