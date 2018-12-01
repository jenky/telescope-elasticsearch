<?php

namespace Jenky\TelescopeElasticsearch\Storage;

use Illuminate\Support\Collection;
use Laravel\Telescope\EntryResult;
use Laravel\Telescope\Storage\EntryQueryOptions;
use Laravel\Telescope\Contracts\EntriesRepository;
use Jenky\TelescopeElasticsearch\HasElasticsearchClient;
use Jenky\TelescopeElasticsearch\Contracts\ElasticsearchClient;

class ElasticsearchEntriesRepository implements EntriesRepository, ElasticsearchClient
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
        $entry = $this->elastic->search([
            'index' => 'telescope_entries',
            'type' => '_doc',
            'body' => [
                'query' => [
                    // 'bool' => [
                    //     'filter' => [
                    //         'term' => [
                    //             ['uuid' => $id],
                    //         ],
                    //     ],
                    // ],
                    'term' => [
                        'uuid' => $id,
                    ],
                ],
                'size' => 1,
            ],
        ]);

        dd($entry);

        return new EntryResult(
            $entry->uuid,
            null,
            $entry->batch_id,
            $entry->type,
            $entry->family_hash,
            $entry->content,
            $entry->created_at,
            $tags
        );
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
        dd( $this->elastic->search([
            'index' => 'telescope_entries',
            'type' => '_doc',
            'body' => [
                'query' => [
                    'bool' => [
                        // 'must' => [
                        //     [
                        //         'query_string' => [
                        //             'query' => $query,
                        //         ],
                        //     ],
                        // ],
                        'filter' => [
                            'term' => array_filter([
                                ['type' => $type],
                                ['batch_id' => $options->batchId],
                                // ['tag' => $options->tag],
                                ['family_hash' => $options->familyHash],
                            ]),
                            'range' => [
                                [
                                    'sequence' => [
                                        'lt' => $options->beforeSequence,
                                    ]
                                ],
                            ],
                        ],
                    ],
                ],
                'size' => $options->limit,
                'sort' => [
                    ['sequence' => 'desc'],
                ]
            ],
        ]) );
            // ->take($options->limit)
            // ->orderByDesc('sequence')
            // ->get()->reject(function ($entry) {
            //     return ! is_array($entry->content);
            // })->map(function ($entry) {
            //     return new EntryResult(
            //         $entry->uuid,
            //         $entry->sequence,
            //         $entry->batch_id,
            //         $entry->type,
            //         $entry->family_hash,
            //         $entry->content,
            //         $entry->created_at,
            //         []
            //     );
            // })
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

        // [$exceptions, $entries] = $entries->partition->isException();

        // // $this->storeExceptions($exceptions);

        $params['body'] = [];

        $entries->each(function ($entry) use (&$params) {
            $params['body'][] = [
                'update' => [
                    '_index' => 'telescope_entries',
                    '_type' => '_doc',
                ],
            ];

            $params['body'][] = [
                'doc' => $entry->toArray(),
                'doc_as_upsert' => true,
            ];
        });

        dd($params);

        $this->elastic->bulk($params);

        // $this->table('telescope_entries')->insert($entries->map(function ($entry) {
        //     $entry->content = json_encode($entry->content);

        //     return $entry->toArray();
        // })->toArray());

        // $this->storeTags($entries->pluck('tags', 'uuid'));
    }

    /**
     * Store the given entry updates.
     *
     * @param  \Illuminate\Support\Collection[\Laravel\Telescope\EntryUpdate]  $updates
     * @return void
     */
    public function update(Collection $updates)
    {
        //
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
