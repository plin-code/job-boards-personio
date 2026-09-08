<p align="center">
  <img src="https://raw.githubusercontent.com/plin-code/job-boards-personio/main/art/banner.png" alt="Job Boards Personio">
</p>

# Job Boards Personio

Personio connector for the [plin-code](https://github.com/plin-code) job boards family. Personio is the odd one out: the board is an XML feed, not JSON, and the slug is a subdomain rather than a path segment.

```
GET https://{slug}.jobs.personio.de/xml?language=en
<workzag-jobs>
  <position><id>1234567</id><subcompany>KoRo Handels GmbH</subcompany><office>Berlin</office>
           <department>Marketing</department><name>Senior Brand Manager</name>...</position>
</workzag-jobs>
```

It implements `PlinCode\JobBoards\Contracts\JobBoardClient` from [`plin-code/job-boards-core`](https://github.com/plin-code/job-boards-core), so it is interchangeable with every other connector in the family. Generated from [`plin-code/job-boards-skeleton`](https://github.com/plin-code/job-boards-skeleton).

## Installation

```bash
composer require plin-code/job-boards-personio
```

## Framework agnostic on purpose

`PersonioClient` takes core's `HttpClient` and an optional PSR-3 logger. It imports nothing from Laravel, so a Symfony or plain PHP consumer builds it directly:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PlinCode\JobBoards\Http\HttpClient;
use PlinCode\JobBoards\Personio\PersonioClient;

$http = new HttpClient(new Client, new HttpFactory);

$client = new PersonioClient($http);

$jobs = $client->fetchJobsForCompany('koro-handels-gmbh');       // list<JobPostingDTO>
$name = $client->validateSlug('koro-handels-gmbh');              // ?string, the subcompany
$about = $client->fetchCompanyDescription('koro-handels-gmbh');  // always null, see below
```

`PersonioServiceProvider` is the only Laravel aware file in the package, and all it does is that same wiring out of the container.

## Laravel usage

The provider is auto discovered.

```php
use PlinCode\JobBoards\Personio\PersonioClient;

$client = app(PersonioClient::class);

foreach ($client->fetchJobsForCompany('koro-handels-gmbh') as $job) {
    JobPosting::updateOrCreate(
        ['external_id' => $job->externalId],
        $job->toArray(),
    );
}
```

Publish the config to change the URL templates, the timeouts or the request headers:

```bash
php artisan vendor:publish --tag=job-boards-personio-config
```

```php
'base_url'         => env('JOB_BOARDS_PERSONIO_BASE_URL', PersonioClient::API_BASE_URL),
'job_url_template' => env('JOB_BOARDS_PERSONIO_JOB_URL_TEMPLATE', PersonioClient::JOB_URL_TEMPLATE),
'timeout'          => env('JOB_BOARDS_PERSONIO_TIMEOUT', 30),
'lookup_timeout'   => env('JOB_BOARDS_PERSONIO_LOOKUP_TIMEOUT', 15),
'headers'          => ['Accept' => 'application/xml'],
```

`base_url` is a `sprintf` template with one `%s` for the slug, not a prefix, because Personio puts the slug in the hostname. `job_url_template` takes the slug then the position id. Personio serves the same feed and the same pages on `jobs.personio.com`; point both templates there if you prefer it.

The provider binds a PSR-18 client and a PSR-17 factory with `bindIf`, so an application that already binds its own keeps it. It deliberately does **not** bind `JobBoardClient` itself: several connectors implement that interface and would fight over the binding. Bind the one you want in your own application service provider.

## Mapping

| `JobPostingDTO` | Personio field |
| --- | --- |
| `externalId` | `<id>`, trimmed |
| `title` | `<name>`, falling back to `'Untitled Position'` |
| `location` | `<office>`, or `null` when empty |
| `url` | built from the slug and the id: the feed carries no link |
| `department` | `<department>`, or `null` when empty |
| `rawPayload` | the whole `<position>` element, run through `json_encode`/`json_decode` |

## The one language retry

A board that publishes in a single language other than English answers `?language=en` with an empty feed rather than with the local copy. So when the first call yields no `<position>` at all, the board is asked exactly once more with no `language` parameter, and the result of that second call is used.

Core's `HttpClient` drops null query values, which is what makes this one call site rather than two:

```php
$this->http->withTimeout($timeout)->get($url, ['language' => $withLanguage ? 'en' : null]);
```

The retry URL is `https://{slug}.jobs.personio.de/xml` with no query string at all, and a test asserts exactly that. The retry happens only in `fetchJobsForCompany()`. `validateSlug()` does not retry: an empty board is still a real board, and it validates under its own slug.

## Slugs land in the hostname

Every other connector in the family puts the slug in the path, where percent encoding is enough. Personio puts it in the host, where a `/` or an `@` is not escaped but silently retargets the request: `evil.com/x` would produce `https://evil.com/x.jobs.personio.de/xml`. Slugs are therefore checked against a host label pattern (`[A-Za-z0-9]`, with `.` and `-` allowed in the middle) before any request goes out. A slug that fails logs a warning and yields an empty list or a `null`, like any other failure.

## No company description

Personio publishes no company profile anywhere in the feed, so `fetchCompanyDescription()` returns `null` without making a request. This is not a stub: there is nothing to fetch.

## Error handling

`fetchJobsForCompany()` never throws at the caller. Everything is logged through the injected PSR-3 logger and an empty list comes back, so one broken company cannot abort a sync over hundreds of them:

| Situation | Level | Message |
| --- | --- | --- |
| slug is not a usable host label | `warning` | `Personio slug is not a usable host label` |
| non 2xx status | `warning` | `Personio XML request failed` |
| body is present but is not XML | `warning` | `Personio XML parse failed` |
| DNS failure, refused connection, timeout | `error` | `Personio connection error` |
| anything else | `error` | `Unexpected error fetching Personio XML` |

An empty body is treated as an absence, not an error, and logs nothing.

Every record carries `company_slug`. With no logger passed, a `NullLogger` is used and everything is silent.

Unlike the other connectors in the family, `validateSlug()` here shares the fetch path and therefore logs the same records: Personio has no separate account endpoint to keep quiet about. It still returns `null` for every failure, including a dead connection.

## Timeouts

`validateSlug()` uses the shorter 15 second budget, `fetchJobsForCompany()` the full 30, and the language retry spends the full 30 again.

PSR-18 has no notion of a timeout, so core's `HttpClient::withTimeout()` is only honoured by clients implementing `PlinCode\JobBoards\Http\SupportsTimeout`. Guzzle's PSR-18 client does not, so these numbers are a request the transport may ignore. If timeouts matter to you, build the Guzzle client with `['timeout' => 30]` and bind it yourself, or wrap it in a small `SupportsTimeout` adapter.

## Depending on core

```json
"require": {
    "plin-code/job-boards-core": "^0.2||^0.3"
}
```

Core is on Packagist, so that constraint is all this package needs: there is no `repositories` block to carry. Do **not** commit a `path` repository pointing at a sibling checkout of core. It resolves against the layout of one machine, and the package then fails to install from a fresh clone anywhere else.

## Development

```bash
composer install
composer lint          # pint, writes
composer lint:check    # pint, read only
composer analyse       # phpstan level 10, matching core
composer test:unit     # pest
composer test          # analyse + lint:check + test:unit
```

`tests/Unit` builds the client against a faked PSR-18 client and boots no framework. `tests/Feature` boots Testbench and covers the service provider only. The PSR-18 test doubles come from core, under `PlinCode\JobBoards\Testing`.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
