<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompressResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Check if the client accepts encoding
        $acceptEncoding = $request->header('Accept-Encoding');

        // Don't compress if the response is already compressed or if it's a binary file
        if (!$response->headers->has('Content-Encoding') && $this->shouldCompress($response)) {
            // Check for Brotli support (higher compression, better performance)
            if (strpos($acceptEncoding, 'br') !== false && function_exists('brotli_compress')) {
                $compressed = brotli_compress($response->getContent(), 4); // Compression level 4 (0-11)
                if ($compressed !== false) {
                    $response->setContent($compressed);
                    $response->headers->set('Content-Encoding', 'br');
                }
            } 
            // Fall back to gzip if brotli is not available or failed
            elseif (strpos($acceptEncoding, 'gzip') !== false) {
                $compressed = gzencode($response->getContent(), 6); // Compression level 6 (0-9)
                if ($compressed !== false) {
                    $response->setContent($compressed);
                    $response->headers->set('Content-Encoding', 'gzip');
                }
            }

            // Remove the content length header as it's no longer valid after compression
            $response->headers->remove('Content-Length');
        }

        return $response;
    }

    /**
     * Determine if the response should be compressed.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return bool
     */
    protected function shouldCompress(Response $response): bool
    {
        // Only compress text-based content types
        $contentType = $response->headers->get('Content-Type');
        if (!$contentType) {
            return false;
        }

        $compressibleTypes = [
            'text/html',
            'text/plain',
            'text/css',
            'text/javascript',
            'application/javascript',
            'application/json',
            'application/xml',
            'application/xhtml+xml',
            'image/svg+xml',
        ];

        foreach ($compressibleTypes as $type) {
            if (strpos($contentType, $type) !== false) {
                return true;
            }
        }

        return false;
    }
}
