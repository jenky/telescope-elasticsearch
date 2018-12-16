<?php

namespace Jenky\TelescopeElasticsearch;

use Carbon\Carbon;
use Cviebrock\LaravelElasticsearch\Facade;
use Jenky\TelescopeElasticsearch\Storage\ElasticsearchResults;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\IncomingEntry;
use ONGR\ElasticsearchDSL\Search;

trait HasElasticsearchClient
{
    /**
     * The elasticsearch client.
     *
     * @var \Elasticsearch\Client
     */
    protected $elastic;

    /**
     * Create a new migration instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->elastic = Facade::connection(
            config('telescope.storage.elasticsearch.connection')
        );
    }

    public function search(Search $search): ElasticsearchResults
    {
        return ElasticsearchResults::make(
            $this->elastic->search([
                'index' => 'telescope',
                'body' => $search->toArray(),
            ])
        );
    }

    public function toIncomingEntry(array $document): IncomingEntry
    {
        $data = $document['_source'] ?? [];

        return tap(IncomingEntry::make($data['content']), function ($entry) {
            $entry->uuid = $data['uuid'];
            $entry->batchId = $data['batch_id'];
            $entry->type = $data['type'];
            $entry->family_hash = $data['family_hash'] ?? null;
            $entry->recordedAt = Carbon::parse($data['created_at']);
            $entry->tags = array_pluck($data['tags'], 'raw');

            if (! empty($data['content']['user'])) {
                $entry->user = $data['content']['user'];
            }
        });
    }

    public function toEntryResult(array $document): EntryResult
    {
        $entry = $document['_source'] ?? [];
        return new EntryResult(
            $entry['uuid'],
            null,
            $entry['batch_id'],
            $entry['type'],
            $entry['family_hash'] ?? null,
            $entry['content'],
            Carbon::parse($entry['created_at']),
            array_pluck($entry['tags'], 'raw')
        );
    }

    public function toEntryResults($results)
    {
        return $results->map(function ($entry) {
            return $this->toEntryResult($entry);
        });
    }
}
