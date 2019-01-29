<?php

namespace Jenky\TelescopeElasticsearch\Storage;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Jenky\TelescopeElasticsearch\Contracts\Installer;
use Jenky\TelescopeElasticsearch\HasElasticsearchClient;
use Jenky\TelescopeElasticsearch\TelescopeIndex;
use Laravel\Telescope\Contracts\ClearableRepository;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Contracts\PrunableRepository;
use Laravel\Telescope\Contracts\TerminableRepository;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Storage\EntryQueryOptions;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\FullText\MatchPhraseQuery;
use ONGR\ElasticsearchDSL\Query\FullText\QueryStringQuery;
use ONGR\ElasticsearchDSL\Query\Joining\NestedQuery;
use ONGR\ElasticsearchDSL\Query\MatchAllQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\RangeQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use ONGR\ElasticsearchDSL\Search;
use ONGR\ElasticsearchDSL\Sort\FieldSort;

class ElasticsearchEntriesRepository implements EntriesRepository, ClearableRepository, PrunableRepository, TerminableRepository
{
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
        // $search = tap(new Search, function ($query) use ($id) {
        //     $query->addQuery(new TermQuery('uuid', $id));
        // });

        // $entry = $this->search($search)->first();
        $entry = TelescopeIndex::term('uuid', $id)->first();

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
        // $search = tap(new Search, function ($query) use ($type, $options) {
        //     if ($type) {
        //         $query->addQuery(new TermQuery('type', $type));
        //     }

        //     if ($options->batchId) {
        //         $query->addQuery(new TermQuery('batch_id', $options->batchId));
        //     }

        //     if ($options->familyHash) {
        //         $query->addQuery(new TermQuery('family_hash', $options->familyHash));
        //     }

        //     if ($options->tag) {
        //         $boolQuery = tap(new BoolQuery, function ($query) use ($options) {
        //             $query->add(new MatchPhraseQuery('tags.raw', $options->tag));
        //         });

        //         $nestedQuery = new NestedQuery(
        //             'tags',
        //             $boolQuery
        //         );

        //         $query->addQuery($nestedQuery);
        //     }

        //     $query->addSort(new FieldSort('created_at', 'desc'))
        //         ->setSize($options->limit);
        // });

        $data = TelescopeIndex::take($options->limit)
            ->orderBy('created_at', 'desc')
            ->when($type, function ($query, $value) {
                $query->term('type', $value);
            })
            ->when($options->batchId, function ($query, $value) {
                $query->term('batch_id', $value);
            })
            ->when($options->familyHash, function ($query, $value) {
                $query->term('family_hash', $value);
            })
            ->when($options->tag, function ($query, $value) {
                $query->nested('tags', function ($q) use ($value) {
                    $q->match('tags.raw', $value);
                });
            })
            ->get();

        return $this->toEntryResults($data)->reject(function ($entry) {
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
            // $search = tap(new Search, function ($query) use ($exception) {
            //     $query->addQuery(new TermQuery('family_hash', $exception->familyHash()))
            //         ->addQuery(new TermQuery('type', EntryType::EXCEPTION))
            //         ->setSize(1000);
            // });

            // $documents = $this->search($search);
            $documents = TelescopeIndex::term('family_hash', $exception->familyHash())
                ->term('type', EntryType::EXCEPTION)
                ->limit(1000);

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

            $occurrences = $documents->map(function ($document) {
                return tap($this->toIncomingEntry($document), function ($entry) {
                    $entry->displayOnIndex = false;
                });
            });

            $entries->merge($occurrences);
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
        $installer = resolve(Installer::class);
        $index = $installer->indexName();

        if (! $this->elastic->indices()->exists(compact('index'))) {
            $installer->install();
        }

        if ($entries->isEmpty()) {
            return;
        }

        $params['body'] = [];

        foreach ($entries as $entry) {
            $params['body'][] = [
                'index' => [
                    '_id' => $entry->uuid,
                    '_index' => TelescopeIndex::getIndex(),
                    '_type' => '_doc',
                ],
            ];

            $data = $entry->toArray();
            $data['uuid'] = $data['uuid'];
            $data['family_hash'] = $entry->familyHash;
            $data['tags'] = $this->formatTags($entry->tags);
            $data['should_display_on_index'] = property_exists($entry, 'displayOnIndex')
                ? $entry->displayOnIndex
                : true;
            $data['@timestamp'] = gmdate('c');

            $params['body'][] = $data;
        }

        TelescopeIndex::getConnection()->bulk($params);
    }

    protected function initIndex()
    {
        if (! TelescopeIndex::exist()) {
            TelescopeIndex::create();
        }
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
            'index' => '.telescope',
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
        $this->elastic->indices()->flush(['index' => '.telescope']);
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
