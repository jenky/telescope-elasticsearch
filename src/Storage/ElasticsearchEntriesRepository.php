<?php

namespace Jenky\TelescopeElasticsearch\Storage;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Jenky\LaravelElasticsearch\Storage\Index;
use Jenky\TelescopeElasticsearch\TelescopeIndex;
use Laravel\Telescope\Contracts\ClearableRepository;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Contracts\PrunableRepository;
use Laravel\Telescope\Contracts\TerminableRepository;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Storage\EntryQueryOptions;

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
                    $q->matchPhrase('tags.raw', $value);
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
            $entry = TelescopeIndex::term('uuid', $update->uuid)
                ->term('type', $update->type)
                ->first();

            if (! $entry) {
                continue;
            }

            $entry['_source']['content'] = array_merge(
                $entry['_source']['content'] ?? [],
                $update->changes
            );

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
            $documents = TelescopeIndex::term('family_hash', $exception->familyHash())
                ->term('type', EntryType::EXCEPTION)
                ->limit(1000)
                ->get();

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
        if ($entries->isEmpty()) {
            return;
        }

        $this->initIndex($index = TelescopeIndex::make());

        $params['body'] = [];

        foreach ($entries as $entry) {
            $params['body'][] = [
                'index' => [
                    '_id' => $entry->uuid,
                    '_index' => $index->getIndex(),
                    '_type' => $index->getType(),
                ],
            ];

            $data = $entry->toArray();
            $data['family_hash'] = $entry->familyHash;
            $data['tags'] = $this->formatTags($entry->tags);
            $data['should_display_on_index'] = property_exists($entry, 'displayOnIndex')
                ? $entry->displayOnIndex
                : true;
            $data['@timestamp'] = gmdate('c');

            $params['body'][] = $data;
        }

        $index->getConnection()->bulk($params);
    }

    /**
     * Create new index if not exists.
     *
     * @param  \Jenky\LaravelElasticsearch\Storage\Index $index
     * @return void
     */
    protected function initIndex(Index $index)
    {
        if (! $index->exists()) {
            $index->create();
        }
    }

    /**
     * Map Elasticsearch document to EntryResult object.
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
        $query = TelescopeIndex::range('created_at', [
            'lt' => (string) $before,
        ])->matchAll();
        $index = $query->getIndex();

        $response = $index->getConnection()->deleteByQuery([
            'index' => '.telescope', // Todo: change hard coded alias
            'body' => $query->toDSL(),
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
        TelescopeIndex::flush('.telescope'); // Todo: change hard coded alias
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
