<?php

declare(strict_types=1);

use PlinCode\JobBoards\Personio\PersonioClient;

return [

    /*
    |--------------------------------------------------------------------------
    | API Base URL
    |--------------------------------------------------------------------------
    |
    | Personio puts the board slug in the hostname, so this is a sprintf
    | template with one "%s", not a prefix. Personio serves the same feed on
    | jobs.personio.com; point this there, or at a recorded fixture server.
    |
    */

    'base_url' => env('JOB_BOARDS_PERSONIO_BASE_URL', PersonioClient::API_BASE_URL),

    /*
    |--------------------------------------------------------------------------
    | Job URL Template
    |--------------------------------------------------------------------------
    |
    | The public job page built for every posting: "%s" is the slug, the second
    | "%s" the position id. The XML feed carries no link of its own.
    |
    */

    'job_url_template' => env('JOB_BOARDS_PERSONIO_JOB_URL_TEMPLATE', PersonioClient::JOB_URL_TEMPLATE),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Seconds. "timeout" covers listing a whole board, "lookup_timeout" the
    | cheaper call behind validateSlug(). Honoured only by PSR-18 clients that
    | implement PlinCode\JobBoards\Http\SupportsTimeout; other clients keep the
    | timeout they were built with.
    |
    */

    'timeout' => env('JOB_BOARDS_PERSONIO_TIMEOUT', 30),

    'lookup_timeout' => env('JOB_BOARDS_PERSONIO_LOOKUP_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Request Headers
    |--------------------------------------------------------------------------
    |
    | Sent with every request. The feed is public and answers XML, so that is
    | what we ask for.
    |
    */

    'headers' => [
        'Accept' => 'application/xml',
    ],

];
