<?php

declare(strict_types=1);

use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Personio\PersonioClient;
use PlinCode\JobBoards\Testing\FakePsrClient;
use PlinCode\JobBoards\Testing\RecordingLogger;

function personioClient(FakePsrClient $fake, ?RecordingLogger $logger = null): PersonioClient
{
    return new PersonioClient($fake->asHttpClient(), logger: $logger);
}

function personioFixture(): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/personio-koro-jobs.xml');
}

function personioXml(string $body): FakePsrClient
{
    return (new FakePsrClient)->respondWith(200, $body, ['Content-Type' => 'application/xml']);
}

const PERSONIO_EMPTY_FEED = '<?xml version="1.0"?><workzag-jobs></workzag-jobs>';

it('fetches jobs from the personio xml feed and maps them to DTOs', function (): void {
    $fake = personioXml(personioFixture());

    $jobs = personioClient($fake)->fetchJobsForCompany('koro-handels-gmbh');

    $additionalOffices = $jobs[1]->rawPayload['additionalOffices'] ?? null;

    expect($jobs)->toHaveCount(2)
        ->and($jobs[0])->toBeInstanceOf(JobPostingDTO::class)
        ->and($jobs[0]->externalId)->toBe('1234567')
        ->and($jobs[0]->title)->toBe('Senior Brand Manager')
        ->and($jobs[0]->location)->toBe('Berlin')
        ->and($jobs[0]->department)->toBe('Marketing')
        ->and($jobs[0]->url)->toBe('https://koro-handels-gmbh.jobs.personio.de/job/1234567?language=en')
        ->and($jobs[0]->rawPayload)->toHaveKey('seniority')
        ->and($jobs[1]->location)->toBe('Frankfurt')
        ->and($additionalOffices)->toBe(['office' => 'Lyon'])
        ->and($fake->lastUri())->toBe('https://koro-handels-gmbh.jobs.personio.de/xml?language=en');
});

it('falls back to a placeholder title when name is empty', function (): void {
    $fake = personioXml('<?xml version="1.0"?><workzag-jobs><position><id>1</id><name></name><office>Berlin</office></position></workzag-jobs>');

    $jobs = personioClient($fake)->fetchJobsForCompany('empty-name');

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->title)->toBe('Untitled Position')
        ->and($jobs[0]->department)->toBeNull();
});

it('returns an empty list on a failed http response', function (): void {
    $fake = (new FakePsrClient)->respondWith(500, 'Server Error');
    $logger = new RecordingLogger;

    expect(personioClient($fake, $logger)->fetchJobsForCompany('broken'))->toBe([])
        ->and($logger->messages())->toBe(['Personio XML request failed'])
        ->and($logger->levels())->toBe(['warning'])
        ->and($logger->records[0]['context'])->toBe(['company_slug' => 'broken', 'status' => 500]);
});

it('returns an empty list on malformed xml', function (): void {
    $fake = (new FakePsrClient)->respondWith(200, '<not-xml<<');
    $logger = new RecordingLogger;

    expect(personioClient($fake, $logger)->fetchJobsForCompany('malformed'))->toBe([])
        ->and($logger->messages())->toBe(['Personio XML parse failed'])
        ->and($logger->levels())->toBe(['warning']);
});

it('returns an empty list on a connection error', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();
    $logger = new RecordingLogger;

    expect(personioClient($fake, $logger)->fetchJobsForCompany('timeout'))->toBe([])
        ->and($logger->messages())->toBe(['Personio connection error'])
        ->and($logger->levels())->toBe(['error'])
        ->and($logger->records[0]['context']['company_slug'])->toBe('timeout')
        ->and($logger->records[0]['context']['error'])->toContain('connection refused');
});

it('returns an empty list on an empty body without logging a parse failure', function (): void {
    $fake = (new FakePsrClient)->respondWith(200, '   ');
    $logger = new RecordingLogger;

    expect(personioClient($fake, $logger)->fetchJobsForCompany('silent'))->toBe([])
        ->and($logger->records)->toBe([]);
});

it('retries without the language parameter when the first response has zero positions', function (): void {
    $fake = personioXml(PERSONIO_EMPTY_FEED);
    $fake->respondWith(200, personioFixture(), ['Content-Type' => 'application/xml']);

    $jobs = personioClient($fake)->fetchJobsForCompany('monolingual');

    expect($jobs)->toHaveCount(2)
        // The retry drops the parameter entirely, it does not send language= or
        // language=null: core's HttpClient throws null query values away.
        ->and($fake->uris())->toBe([
            'https://monolingual.jobs.personio.de/xml?language=en',
            'https://monolingual.jobs.personio.de/xml',
        ]);
});

it('gives up after the retry when the board is genuinely empty', function (): void {
    $fake = personioXml(PERSONIO_EMPTY_FEED);
    $fake->respondWith(200, PERSONIO_EMPTY_FEED, ['Content-Type' => 'application/xml']);

    expect(personioClient($fake)->fetchJobsForCompany('nothing'))->toBe([])
        ->and($fake->uris())->toHaveCount(2);
});

it('does not retry when the first call already found positions', function (): void {
    $fake = personioXml(personioFixture());

    personioClient($fake)->fetchJobsForCompany('koro-handels-gmbh');

    expect($fake->uris())->toHaveCount(1);
});

it('gives up after the retry when the retry itself fails', function (): void {
    $fake = personioXml(PERSONIO_EMPTY_FEED);
    $fake->respondWith(503, 'Service Unavailable');
    $logger = new RecordingLogger;

    expect(personioClient($fake, $logger)->fetchJobsForCompany('flaky'))->toBe([])
        ->and($logger->messages())->toBe(['Personio XML request failed']);
});

it('validateSlug returns the subcompany name when present', function (): void {
    $fake = personioXml(personioFixture());

    expect(personioClient($fake)->validateSlug('koro-handels-gmbh'))->toBe('KoRo Handels GmbH')
        ->and($fake->lastUri())->toBe('https://koro-handels-gmbh.jobs.personio.de/xml?language=en');
});

it('validateSlug falls back to the slug when subcompany is empty', function (): void {
    $fake = personioXml('<?xml version="1.0"?><workzag-jobs><position><id>1</id><subcompany></subcompany><name>Job</name></position></workzag-jobs>');

    expect(personioClient($fake)->validateSlug('empty-sub'))->toBe('empty-sub');
});

it('validateSlug falls back to the slug when the board is valid but empty', function (): void {
    // A live board with nothing published is still a real board, and it is not
    // retried without the language parameter the way a fetch is.
    $fake = personioXml(PERSONIO_EMPTY_FEED);

    expect(personioClient($fake)->validateSlug('quiet'))->toBe('quiet')
        ->and($fake->uris())->toHaveCount(1);
});

it('validateSlug returns null on an http error, malformed xml or a dead connection', function (): void {
    expect(personioClient((new FakePsrClient)->respondWith(404, 'Not Found'))->validateSlug('gone'))->toBeNull()
        ->and(personioClient((new FakePsrClient)->respondWith(200, '<not-xml<<'))->validateSlug('malformed'))->toBeNull()
        ->and(personioClient((new FakePsrClient)->throwNetworkError())->validateSlug('timeout'))->toBeNull();
});

it('fetchCompanyDescription always returns null and never calls out', function (): void {
    $fake = new FakePsrClient;

    expect(personioClient($fake)->fetchCompanyDescription('any-slug'))->toBeNull()
        ->and($fake->requests)->toBe([]);
});

it('asks for 30 seconds when listing and 15 when looking up', function (): void {
    $fake = personioXml(personioFixture());
    $fake->respondWith(200, personioFixture(), ['Content-Type' => 'application/xml']);

    $client = personioClient($fake);
    $client->fetchJobsForCompany('koro-handels-gmbh');
    $client->validateSlug('koro-handels-gmbh');

    expect($fake->appliedTimeouts)->toBe([30.0, 15.0]);
});

it('spends the listing timeout on the retry too', function (): void {
    $fake = personioXml(PERSONIO_EMPTY_FEED);
    $fake->respondWith(200, PERSONIO_EMPTY_FEED, ['Content-Type' => 'application/xml']);

    personioClient($fake)->fetchJobsForCompany('monolingual');

    expect($fake->appliedTimeouts)->toBe([30.0, 30.0]);
});

it('refuses a slug that would retarget the request at another host', function (): void {
    // The slug lands in the hostname, where a slash is not escaped: without this
    // guard "evil.com/x" would produce https://evil.com/x.jobs.personio.de/xml.
    $fake = new FakePsrClient;
    $logger = new RecordingLogger;

    $client = personioClient($fake, $logger);

    expect($client->fetchJobsForCompany('evil.com/x'))->toBe([])
        ->and($client->validateSlug('user@evil.com'))->toBeNull()
        ->and($client->fetchJobsForCompany('has space'))->toBe([])
        ->and($client->fetchJobsForCompany(''))->toBe([])
        ->and($client->fetchJobsForCompany('-leading-dash'))->toBe([])
        ->and($fake->requests)->toBe([])
        ->and($logger->messages())->toBe(array_fill(0, 5, 'Personio slug is not a usable host label'));
});

it('accepts the slug shapes Personio actually issues', function (): void {
    foreach (['koro-handels-gmbh', 'acme', 'a1', 'sub.acme'] as $slug) {
        $fake = personioXml(PERSONIO_EMPTY_FEED);
        personioClient($fake)->validateSlug($slug);

        expect($fake->uris())->toBe(["https://{$slug}.jobs.personio.de/xml?language=en"]);
    }
});

it('is safe with no logger at all', function (): void {
    $fake = (new FakePsrClient)->throwNetworkError();

    expect((new PersonioClient($fake->asHttpClient()))->fetchJobsForCompany('testco'))->toBe([]);
});

it('accepts custom base and job url templates', function (): void {
    $fake = personioXml(personioFixture());

    $jobs = (new PersonioClient(
        $fake->asHttpClient(),
        'https://fixtures.test/%s/xml',
        'https://fixtures.test/%s/job/%s',
    ))->fetchJobsForCompany('koro-handels-gmbh');

    expect($fake->lastUri())->toBe('https://fixtures.test/koro-handels-gmbh/xml?language=en')
        ->and($jobs[0]->url)->toBe('https://fixtures.test/koro-handels-gmbh/job/1234567');
});
