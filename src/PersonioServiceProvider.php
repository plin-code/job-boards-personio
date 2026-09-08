<?php

declare(strict_types=1);

namespace PlinCode\JobBoards\Personio;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Foundation\Application;
use PlinCode\JobBoards\Http\HttpClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The only Laravel aware file in this package. It wires the PSR-18 client,
 * the PSR-17 request factory and core's HttpClient into the container, then
 * hands them to {@see PersonioClient}.
 *
 * Everything it does by hand is a few lines, which is the point: outside
 * Laravel you construct the client yourself and skip this file entirely.
 */
final class PersonioServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('job-boards-personio')
            ->hasConfigFile('job-boards-personio');
    }

    public function packageRegistered(): void
    {
        // bindIf, so an application that already ships its own PSR-18 client or
        // PSR-17 factory keeps it. Only fall back to Guzzle when nothing is bound.
        $this->app->bindIf(ClientInterface::class, static fn (): ClientInterface => new GuzzleClient);
        $this->app->bindIf(RequestFactoryInterface::class, static fn (): RequestFactoryInterface => new HttpFactory);

        $this->app->bind(PersonioClient::class, function (Application $app): PersonioClient {
            /** @var array{base_url?: mixed, job_url_template?: mixed, timeout?: mixed, lookup_timeout?: mixed, headers?: mixed} $config */
            $config = $app->make('config')->get('job-boards-personio', []);

            $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];

            /** @var array<string, string> $headers */
            $http = new HttpClient(
                $app->make(ClientInterface::class),
                $app->make(RequestFactoryInterface::class),
            );

            return new PersonioClient(
                $http->withHeaders($headers),
                is_string($config['base_url'] ?? null) ? $config['base_url'] : PersonioClient::API_BASE_URL,
                is_string($config['job_url_template'] ?? null) ? $config['job_url_template'] : PersonioClient::JOB_URL_TEMPLATE,
                is_numeric($config['timeout'] ?? null) ? (float) $config['timeout'] : PersonioClient::TIMEOUT_SECONDS,
                is_numeric($config['lookup_timeout'] ?? null) ? (float) $config['lookup_timeout'] : PersonioClient::LOOKUP_TIMEOUT_SECONDS,
                $app->make(LoggerInterface::class),
            );
        });
    }
}
