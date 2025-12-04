<?php

namespace SocialDept\AtpParity\Support;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpClient\AtpClient;
use SocialDept\AtpClient\Data\Responses\Atproto\Repo\GetRecordResponse;
use SocialDept\AtpClient\Facades\Atp;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpResolver\Facades\Resolver;
use SocialDept\AtpSchema\Data\Data;

/**
 * Helper for integrating atp-parity with atp-client.
 *
 * Provides convenient methods for fetching records from the ATP network
 * and converting them to typed DTOs or Eloquent models.
 */
class RecordHelper
{
    /**
     * Cache of clients by PDS endpoint.
     *
     * @var array<string, AtpClient>
     */
    protected array $clients = [];

    public function __construct(
        protected MapperRegistry $registry
    ) {}

    /**
     * Get or create a client for a PDS endpoint.
     */
    protected function clientFor(string $pdsEndpoint): AtpClient
    {
        return $this->clients[$pdsEndpoint] ??= Atp::public($pdsEndpoint);
    }

    /**
     * Resolve the PDS endpoint for a DID or handle.
     */
    protected function resolvePds(string $actor): ?string
    {
        return Resolver::resolvePds($actor);
    }

    /**
     * Convert a GetRecordResponse to a typed record DTO.
     *
     * @template T of Data
     *
     * @param  class-string<T>|null  $recordClass  Explicit record class, or null to auto-detect from mapper
     * @return T|array The typed record, or raw array if no mapper found and no class specified
     */
    public function hydrateRecord(GetRecordResponse $response, ?string $recordClass = null): mixed
    {
        if ($recordClass) {
            return $recordClass::fromArray($response->value);
        }

        $collection = $this->extractCollection($response->uri);
        $mapper = $this->registry->forLexicon($collection);

        if (! $mapper) {
            return $response->value;
        }

        $recordClass = $mapper->recordClass();

        return $recordClass::fromArray($response->value);
    }

    /**
     * Fetch a record from the ATP network by URI and return as typed DTO.
     *
     * @template T of Data
     *
     * @param  class-string<T>|null  $recordClass
     * @return T|array|null
     */
    public function fetch(string $uri, ?string $recordClass = null): mixed
    {
        $parts = $this->parseUri($uri);

        if (! $parts) {
            return null;
        }

        $pdsEndpoint = $this->resolvePds($parts['repo']);

        if (! $pdsEndpoint) {
            return null;
        }

        $response = $this->clientFor($pdsEndpoint)->atproto->repo->getRecord(
            $parts['repo'],
            $parts['collection'],
            $parts['rkey']
        );

        return $this->hydrateRecord($response, $recordClass);
    }

    /**
     * Fetch a record by URI and convert directly to an Eloquent model.
     *
     * @template TModel of Model
     *
     * @return TModel|null
     */
    public function fetchAsModel(string $uri): ?Model
    {
        $parts = $this->parseUri($uri);

        if (! $parts) {
            return null;
        }

        $mapper = $this->registry->forLexicon($parts['collection']);

        if (! $mapper) {
            return null;
        }

        $pdsEndpoint = $this->resolvePds($parts['repo']);

        if (! $pdsEndpoint) {
            return null;
        }

        $response = $this->clientFor($pdsEndpoint)->atproto->repo->getRecord(
            $parts['repo'],
            $parts['collection'],
            $parts['rkey']
        );

        $recordClass = $mapper->recordClass();
        $record = $recordClass::fromArray($response->value);

        return $mapper->toModel($record, [
            'uri' => $response->uri,
            'cid' => $response->cid,
        ]);
    }

    /**
     * Fetch a record by URI and upsert to the database.
     *
     * @template TModel of Model
     *
     * @return TModel|null
     */
    public function sync(string $uri): ?Model
    {
        $parts = $this->parseUri($uri);

        if (! $parts) {
            return null;
        }

        $mapper = $this->registry->forLexicon($parts['collection']);

        if (! $mapper) {
            return null;
        }

        $pdsEndpoint = $this->resolvePds($parts['repo']);

        if (! $pdsEndpoint) {
            return null;
        }

        $response = $this->clientFor($pdsEndpoint)->atproto->repo->getRecord(
            $parts['repo'],
            $parts['collection'],
            $parts['rkey']
        );

        $recordClass = $mapper->recordClass();
        $record = $recordClass::fromArray($response->value);

        return $mapper->upsert($record, [
            'uri' => $response->uri,
            'cid' => $response->cid,
        ]);
    }

    /**
     * Parse an AT Protocol URI into its components.
     *
     * @return array{repo: string, collection: string, rkey: string}|null
     */
    protected function parseUri(string $uri): ?array
    {
        // at://did:plc:xxx/app.bsky.feed.post/rkey
        if (! preg_match('#^at://([^/]+)/([^/]+)/([^/]+)$#', $uri, $matches)) {
            return null;
        }

        return [
            'repo' => $matches[1],
            'collection' => $matches[2],
            'rkey' => $matches[3],
        ];
    }

    /**
     * Extract collection from AT Protocol URI.
     */
    protected function extractCollection(string $uri): string
    {
        // at://did:plc:xxx/app.bsky.feed.post/rkey
        if (preg_match('#^at://[^/]+/([^/]+)/#', $uri, $matches)) {
            return $matches[1];
        }

        return '';
    }
}
