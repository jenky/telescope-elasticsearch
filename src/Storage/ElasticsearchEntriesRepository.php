<?php

namespace Jenky\TelescopeElasticsearch\Storage;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Telescope\EntryType;
use ONGR\ElasticsearchDSL\Search;
use Illuminate\Support\Collection;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\IncomingEntry;
use ONGR\ElasticsearchDSL\Sort\FieldSort;
use ONGR\ElasticsearchDSL\Query\MatchAllQuery;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Contracts\PrunableRepository;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use Laravel\Telescope\Contracts\ClearableRepository;
use ONGR\ElasticsearchDSL\Query\Joining\NestedQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use Laravel\Telescope\Contracts\TerminableRepository;
use ONGR\ElasticsearchDSL\Query\TermLevel\RangeQuery;
use Jenky\TelescopeElasticsearch\HasElasticsearchClient;
use ONGR\ElasticsearchDSL\Query\FullText\MatchPhraseQuery;
use ONGR\ElasticsearchDSL\Query\FullText\QueryStringQuery;

class ElasticsearchEntriesRepository implements EntriesRepository, ClearableRepository, PrunableRepository, TerminableRepository
{
    use HasElasticsearchClient;

    /**
     * The tags currently being monitored.
     *
     * @var array|null
     */
    protected $monitoredTags;

    /**
     * Return an entry with the given ID.
     *
     * @param  mixed  $id
     * @return \Laravel\Telescope\EntryResult
     */
    public function find($id) : EntryResult
    {
        $search = tap(new Search, function ($query) use ($id) {
            $query->addQuery(new TermQuery('uuid', $id));
        });

        $entry = $this->search($search)->first();

        if (! $entry) {
            throw new \Exception('Entry not found');
        }

        return $this->toEntryResult($entry);
    }

    /**
     * Return all the entries of a given type.
     *
     * @param  string|null  $type
     * @param  \Laravel\Telescope\Storage\EntryQueryOptions  $options
     * @return \Illuminate\Support\Collection[\Laravel\Telescope\EntryResult]
     */
    public function get($type, EntryQueryOptions $options)
    {
        $search = tap(new Search, function ($query) use ($type, $options) {
            if ($type) {
                $query->addQuery(new TermQuery('type', $type));
            }

            if ($options->batchId) {
                $query->addQuery(new TermQuery('batch_id', $options->batchId));
            }

            if ($options->familyHash) {
                $query->addQuery(new TermQuery('family_hash', $options->familyHash));
            }

            if ($options->tag) {
                $boolQuery = tap(new BoolQuery, function ($query) use ($options) {
                    $query->add(new MatchPhraseQuery('tags.raw', $options->tag));
                    // $query->add(new QueryStringQuery($options->tag, [
                    //     'fields' => ['tags.raw'],
                    // ]));
                });

                $nestedQuery = new NestedQuery(
                    'tags',
                    $boolQuery
                );

                $query->addQuery($nestedQuery);
            }

            $query->addSort(new FieldSort('created_at', 'desc'))
                ->setSize($options->limit);
        });

        return $this->toEntryResults($this->search($search))->reject(function ($entry) {
            return ! is_array($entry->content);
        });
    }

    /**
     * Store the given entries.
     *
     * @param  \Illuminate\Support\Collection[\Laravel\Telescope\IncomingEntry]  $entries
     * @return void
     */
    public function store(Collection $entries):void
    {
        if ($entries->isEmpty()) {
            return;
        }

        [$exceptions, $entries] = $entries->partition->isException();

        $this->storeExceptions($exceptions);

        $this->bulkSend($entries);
    }

    /**
     * Store the given entry updates.
     *
     * @param  \Illuminate\Support\Collection[\Laravel\Telescope\EntryUpdate]  $updates
     * @return void
     */
    public function update(Collection $updates):void
    {
        $entries = [];

        foreach ($updates as $update) {
            $search = tap(new Search, function ($query) use ($update) {
                $query->addQuery(new TermQuery('uuid', $update->uuid))
                    ->addQuery(new TermQuery('type', $update->type));
            });

            $entry = $this->search($search)->first();

            if (! $entry) {
                continue;
            }

            $entry['_source']['content'] = array_merge($entry['_source']['content'] ?? [], $update->changes);

            $entries[] = tap($this->toIncomingEntry($entry), function ($e) use ($update) {
                $e->tags($this->updateTags($update, $e->tags));
            });
        }

        $this->bulkSend(collect($entries));
    }

    /**
     * Update tags of the given entry.
     *
     * @param  \Laravel\Telescope\EntryUpdate  $update
     * @param  array $tags
     * @return array
     */
    protected function updateTags(EntryUpdate $update, array $tags)
    {
        if (! empty($update->tagsChanges['added'])) {
            $tags = array_unique(
                array_merge($tags, $update->tagsChanges['added'])
            );
        }

        if (! empty($update->tagsChanges['removed'])) {
            Arr::forget($tags, $update->tagsChanges['removed']);
        }

        return $tags;
    }

    /**
     * Store the given array of exception entries.
     *
     * @param  \Illuminate\Support\Collection|\Laravel\Telescope\IncomingEntry[]  $exceptions
     * @return void
     */
    protected function storeExceptions(Collection $exceptions)
    {
        $entries = collect([]);

        $exceptions->map(function ($exception) use ($entries) {
            $search = tap(new Search, function ($query) use ($exception) {
                $query->addQuery(new TermQuery('family_hash', $exception->familyHash()))
                    ->addQuery(new TermQuery('type', EntryType::EXCEPTION))
                    ->setSize(1000);
            });

            $documents = $this->search($search);

            $content = array_merge(
                $exception->content,
                ['occurrences' => $documents->total() + 1]
            );

            $entries->merge(
                $documents->map(function ($document) {
                    return tap($this->toIncomingEntry($document), function ($entry) {
                        $entry->displayOnIndex = false;
                    });
                })
            );

            $exception->content = $content;
            $exception->familyHash = $exception->familyHash();
            $exception->tags([
                get_class($exception->exception),
            ]);

            $entries->push($exception);
        });

        $this->bulkSend($entries);
    }

    /**
     * Use Elasticsearch bulk API to send list of documents.
     *
     * @param  \Illuminate\Support\Collection $entries
     * @return void
     */
    protected function bulkSend(Collection $entries): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        $params['body'] = [];

        foreach ($entries as $entry) {
            $params['body'][] = [
                'index' => [
                    '_id' => $entry->uuid,
                    '_index' => 'telescope',
                    '_type' => '_doc',
                ],
            ];

            $data = $entry->toArray();
            $data['family_hash'] = $entry->familyHash;
            $data['tags'] = $this->formatTags($entry->tags);
            $data['should_display_on_index'] = property_exists($entry, 'displayOnIndex')
                ? $entry->displayOnIndex
                : true;

            $params['body'][] = $data;
        }

        $this->elastic->bulk($params);
    }

    /**
     * Format tags to elasticsearch input.
     *
     * @param  array $tags
     * @return array
     */
    protected function formatTags(array $tags): array
    {
        $formatted = [];

        foreach ($tags as $tag) {
            if (Str::contains($tag, ':')) {
                [$name, $value] = explode(':', $tag);
            } else {
                $name = $tag;
                $value = null;
            }

            $formatted[] = [
                'raw' => $tag,
                'name' => $name,
                'value' => $value,
            ];
        }

        return $formatted;
    }

    /**
     * Map Elasticsearch result to IncomingEntry object.
     *
     * @param  array $document
     * @return \Laravel\Telescope\IncomingEntry
     */
    public function toIncomingEntry(array $document): IncomingEntry
    {
        $data = $document['_source'] ?? [];

        return tap(IncomingEntry::make($data['content']), function ($entry) use ($data) {
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

    /**
     * Map Elasticsearch result to EntryResult object.
     *
     * @param  array $document
     * @return \Laravel\Telescope\EntryResult
     */
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

    /**
     * Map Elasticsearch result to EntryResult collection.
     *
     * @param  array $document
     * @return \Illuminate\Support\Collection
     */
    public function toEntryResults($results)
    {
        return $results->map(function ($entry) {
            return $this->toEntryResult($entry);
        });
    }

    /**
     * Load the monitored tags from storage.
     *
     * @return void
     */
    public function loadMonitoredTags()
    {
        try {
            $this->monitoredTags = $this->monitoring();
        } catch (\Throwable $e) {
            $this->monitoredTags = [];
        }
    }

    /**
     * Determine if any of the given tags are currently being monitored.
     *
     * @param  array  $tags
     * @return bool
     */
    public function isMonitoring(array $tags)
    {
        if (is_null($this->monitoredTags)) {
            $this->loadMonitoredTags();
        }

        return count(array_intersect($tags, $this->monitoredTags)) > 0;
    }

    /**
     * Get the list of tags currently being monitored.
     *
     * @return array
     */
    public function monitoring()
    {
        return [];
    }

    /**
     * Begin monitoring the given list of tags.
     *
     * @param  array  $tags
     * @return void
     */
    public function monitor(array $tags)
    {
        $tags = array_diff($tags, $this->monitoring());

        if (empty($tags)) {
            return;
        }

        //
    }

    /**
     * Stop monitoring the given list of tags.
     *
     * @param  array  $tags
     * @return void
     */
    public function stopMonitoring(array $tags)
    {
        //
    }

    /**
     * Prune all of the entries older than the given date.
     *
     * @param  \DateTimeInterface  $before
     * @return int
     */
    public function prune(DateTimeInterface $before)
    {
        $search = tap(new Search, function ($query) use ($before) {
            $query->addQuery(new RangeQuery('created_at', [
                'lt' => (string) $before,
            ]))->addQuery(new MatchAllQuery);
        });

        $response = $this->elastic->deleteByQuery([
            'index' => 'telescope',
            'type' => '_doc',
            'body' => $search->toArray(),
        ]);

        return $response['total'] ?? 0;
    }

    /**
     * Clear all the entries.
     *
     * @return void
     */
    public function clear()
    {
        $this->elastic->indices()->flush(['index' => 'telescope']);
    }

    /**
     * Perform any clean-up tasks needed after storing Telescope entries.
     *
     * @return void
     */
    public function terminate()
    {
        $this->monitoredTags = null;
    }
}
