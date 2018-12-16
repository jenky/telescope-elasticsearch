<?php

namespace Jenky\TelescopeElasticsearch\Storage;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Jenky\TelescopeElasticsearch\HasElasticsearchClient;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\Storage\EntryQueryOptions;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use ONGR\ElasticsearchDSL\Search;
use ONGR\ElasticsearchDSL\Sort\FieldSort;

class ElasticsearchEntriesRepository implements EntriesRepository
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

            $query->addSort(new FieldSort('created_at', 'desc'))
                ->setSize($options->limit);
        });

        return $this->toEntryResults($this->search($search));
    }

    /**
     * Store the given entries.
     *
     * @param  \Illuminate\Support\Collection[\Laravel\Telescope\IncomingEntry]  $entries
     * @return void
     */
    public function store(Collection $entries)
    {
        if ($entries->isEmpty()) {
            return;
        }

        [$exceptions, $entries] = $entries->partition->isException();

        // $this->storeExceptions($exceptions);

        $this->bulkSend($entries);
    }

    /**
     * Store the given entry updates.
     *
     * @param  \Illuminate\Support\Collection[\Laravel\Telescope\EntryUpdate]  $updates
     * @return void
     */
    public function update(Collection $updates)
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

            $entry['content'] = array_merge($entry['content'], $update->changes);

            $entries[] = $this->toIncomingEntry($entry);
        }

        $this->bulkSend(collect($entries));
    }

    /**
     * Store the given array of exception entries.
     *
     * @param  \Illuminate\Support\Collection|\Laravel\Telescope\IncomingEntry[]  $exceptions
     * @return void
     */
    protected function storeExceptions(Collection $exceptions)
    {
        $this->table('telescope_entries')->insert($exceptions->map(function ($exception) {
            $occurrences = $this->table('telescope_entries')
                    ->where('type', EntryType::EXCEPTION)
                    ->where('family_hash', $exception->familyHash())
                    ->count();

            $this->table('telescope_entries')
                    ->where('type', EntryType::EXCEPTION)
                    ->where('family_hash', $exception->familyHash())
                    ->update(['should_display_on_index' => false]);

            return array_merge($exception->toArray(), [
                'family_hash' => $exception->familyHash(),
                'content' => json_encode(array_merge(
                    $exception->content,
                    ['occurrences' => $occurrences + 1]
                )),
            ]);
        })->toArray());

        $this->storeTags($exceptions->pluck('tags', 'uuid'));
    }

    protected function bulkSend(Collection $entries)
    {
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
            $data['tags'] = $this->formatTags($entry->tags);

            $params['body'][] = $data;
        }

        $this->elastic->bulk($params);
    }

    protected function formatTags(array $tags)
    {
        $formatted = [];

        foreach ($tags as $tag) {
            if (Str::contains($tag, ':')) {
                [$name, $value] = explode(':', $tag);
                $formatted[] = [
                    'raw' => $tag,
                    'name' => $name,
                    'value' => $value,
                ];
            }
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
        //
    }

    /**
     * Determine if any of the given tags are currently being monitored.
     *
     * @param  array  $tags
     * @return bool
     */
    public function isMonitoring(array $tags)
    {
        //
    }

    /**
     * Get the list of tags currently being monitored.
     *
     * @return array
     */
    public function monitoring()
    {
        //
    }

    /**
     * Begin monitoring the given list of tags.
     *
     * @param  array  $tags
     * @return void
     */
    public function monitor(array $tags)
    {
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
     * Perform any clean-up tasks needed after storing Telescope entries.
     *
     * @return void
     */
    public function terminate()
    {
        $this->monitoredTags = null;
    }
}
