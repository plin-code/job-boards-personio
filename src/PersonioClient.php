<?php

declare(strict_types=1);

namespace PlinCode\JobBoards\Personio;

use PlinCode\JobBoards\Contracts\JobBoardClient;
use PlinCode\JobBoards\Data\JobPostingDTO;
use PlinCode\JobBoards\Exceptions\TransportException;
use PlinCode\JobBoards\Http\HttpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SimpleXMLElement;
use Throwable;

/**
 * Reads the public Personio XML feed. Personio is the odd one out in this
 * family: the board is XML, not JSON, and the slug is a subdomain rather than a
 * path segment.
 *
 *   GET https://{slug}.jobs.personio.de/xml?language=en
 *   <workzag-jobs><position><id/><subcompany/><office/><department/><name/>...</position></workzag-jobs>
 *
 * Nothing here knows about Laravel. It is handed core's HttpClient and an
 * optional PSR-3 logger, both of which a Symfony or plain PHP consumer can
 * build by hand. {@see PersonioServiceProvider} is the only Laravel aware file.
 */
final class PersonioClient implements JobBoardClient
{
    /**
     * The slug is a subdomain, so the base URL is a template rather than a
     * prefix. Personio serves the same board on .com; either works.
     */
    public const string API_BASE_URL = 'https://%s.jobs.personio.de/xml';

    /**
     * The public job page: slug, then position id.
     */
    public const string JOB_URL_TEMPLATE = 'https://%s.jobs.personio.de/job/%s?language=en';

    /**
     * Listing a whole board can be slow, so it gets a longer budget than the
     * cheap lookup behind validateSlug().
     */
    public const float TIMEOUT_SECONDS = 30.0;

    public const float LOOKUP_TIMEOUT_SECONDS = 15.0;

    /**
     * The slug is interpolated into the hostname, where a slash or an @ would
     * not be escaped but would silently retarget the request at another host.
     * Only what can legitimately appear in a host label gets through.
     */
    private const string SLUG_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrlTemplate = self::API_BASE_URL,
        private readonly string $jobUrlTemplate = self::JOB_URL_TEMPLATE,
        private readonly float $timeout = self::TIMEOUT_SECONDS,
        private readonly float $lookupTimeout = self::LOOKUP_TIMEOUT_SECONDS,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Never throws at the caller: whatever goes wrong is logged and an empty
     * list comes back, so one broken company cannot abort a sync over hundreds
     * of them.
     *
     * @return list<JobPostingDTO>
     */
    public function fetchJobsForCompany(string $slug): array
    {
        $xml = $this->fetchXml($slug, withLanguage: true, timeout: $this->timeout);

        if ($xml === null) {
            return [];
        }

        $positions = $this->positions($xml);

        // Boards that publish in a single language other than English answer
        // ?language=en with an empty feed rather than with the local copy, so a
        // board that looks empty is asked once more without the parameter.
        if ($positions === []) {
            $retry = $this->fetchXml($slug, withLanguage: false, timeout: $this->timeout);
            $positions = $retry === null ? [] : $this->positions($retry);
        }

        return array_map(
            fn (SimpleXMLElement $position): JobPostingDTO => $this->mapToDTO($position, $slug),
            $positions,
        );
    }

    /**
     * Personio has no account endpoint, so the feed itself is the lookup: the
     * subcompany of the first position is the closest thing to a company name.
     * A board that exists but publishes nothing still validates, under its slug.
     */
    public function validateSlug(string $slug): ?string
    {
        $xml = $this->fetchXml($slug, withLanguage: true, timeout: $this->lookupTimeout);

        if ($xml === null) {
            return null;
        }

        $first = $this->positions($xml)[0] ?? null;
        $subcompany = $first !== null ? trim((string) $first->subcompany) : '';

        return $subcompany !== '' ? $subcompany : $slug;
    }

    /**
     * Personio publishes no company profile anywhere in the XML feed, so there
     * is nothing to return.
     */
    public function fetchCompanyDescription(string $slug): ?string
    {
        return null;
    }

    private function fetchXml(string $slug, bool $withLanguage, float $timeout): ?SimpleXMLElement
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $this->logger->warning('Personio slug is not a usable host label', [
                'company_slug' => $slug,
            ]);

            return null;
        }

        $url = sprintf($this->baseUrlTemplate, $slug);

        try {
            // get() rather than tryGet() so the transport error message survives
            // into the log. tryGet() would flatten it to a null. Core's
            // HttpClient drops null query values, so the retry pass sends no
            // language parameter at all.
            $response = $this->http->withTimeout($timeout)->get($url, [
                'language' => $withLanguage ? 'en' : null,
            ]);

            if ($response->failed()) {
                $this->logger->warning('Personio XML request failed', [
                    'company_slug' => $slug,
                    'status' => $response->status(),
                ]);

                return null;
            }

            // XML, so body() rather than json(). Nothing in this connector ever
            // decodes the response as JSON.
            $body = trim($response->body());

            if ($body === '') {
                return null;
            }

            $previousErrors = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);

            if ($xml === false) {
                $this->logger->warning('Personio XML parse failed', ['company_slug' => $slug]);

                return null;
            }

            return $xml;
        } catch (TransportException $e) {
            $this->logger->error('Personio connection error', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error fetching Personio XML', [
                'company_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function positions(SimpleXMLElement $xml): array
    {
        $found = $xml->xpath('//position');

        return is_array($found) ? array_values($found) : [];
    }

    private function mapToDTO(SimpleXMLElement $position, string $slug): JobPostingDTO
    {
        $id = trim((string) $position->id);
        $title = trim((string) $position->name);
        $office = trim((string) $position->office);
        $department = trim((string) $position->department);

        $decoded = json_decode((string) json_encode($position), true);

        /** @var array<string, mixed> $raw */
        $raw = is_array($decoded) ? $decoded : [];

        return new JobPostingDTO(
            externalId: $id,
            title: $title !== '' ? $title : 'Untitled Position',
            location: $office !== '' ? $office : null,
            url: sprintf($this->jobUrlTemplate, $slug, $id),
            department: $department !== '' ? $department : null,
            rawPayload: $raw,
        );
    }
}
