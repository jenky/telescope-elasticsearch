<?php

namespace Jenky\TelescopeElasticsearch\Console;

use Jenky\TelescopeElasticsearch\Contracts\Installer;
use Jenky\TelescopeElasticsearch\Storage\ElasticsearchClient;

class CreateTelescopeIndices implements Installer
{
    /**
     * @var \Jenky\TelescopeElasticsearch\Storage\ElasticsearchClient
     */
    protected $elastic;

    /**
     * Create installer instance.
     *
     * @param  \Jenky\TelescopeElasticsearch\Storage\ElasticsearchClient $elastic
     * @return void
     */
    public function __construct(ElasticsearchClient $elastic)
    {
        $this->elastic = $elastic;
    }

    /**
     * Install.
     *
     * @return void
     */
    public function install()
    {
        $this->createEntriesIndex();
        $this->createTagsIndex();
        $this->createMonitoringIndex();
    }

    /**
     * Uninstall.
     *
     * @return void
     */
    public function uninstall()
    {
        $indices = [
            'telescope_entries',
            'telescope_entries_tags',
            'telescope_monitoring',
        ];

        foreach ($indices as $index ) {
            $this->elastic->indices()->delete(compact('index'));
        }
    }

    protected function createEntriesIndex()
    {
        $this->elastic->indices()->create([
            'index' => 'telescope_entries',
            'body' => [
                // 'settings' => [

                // ],
                'mappings' => [
                    '_doc' => [
                        '_source' => [
                            'enabled' => true
                        ],
                        'properties' => [
                            'sequence' => [
                                'type' => 'long',
                            ],
                            'uuid' => [
                                'type' => 'keyword',
                            ],
                            'batch_id' => [
                                'type' => 'keyword',
                            ],
                            'family_hash' => [
                                'type' => 'keyword',
                            ],
                            'should_display_on_index' => [
                                'type' => 'boolean',
                            ],
                            'type' => [
                                'type' => 'keyword',
                            ],
                            'content' => [
                                'type' => 'object',
                            ],
                            'created_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss',
                            ],
                        ]
                    ],
                ],
            ],
        ]);
    }

    protected function createTagsIndex()
    {
        $this->elastic->indices()->create([
            'index' => 'telescope_entries_tags',
            'body' => [
                // 'settings' => [

                // ],
                'mappings' => [
                    '_doc' => [
                        '_source' => [
                            'enabled' => true
                        ],
                        'properties' => [
                            'entry_uuid' => [
                                'type' => 'keyword',
                            ],
                            'tag' => [
                                'type' => 'keyword',
                            ],
                        ]
                    ],
                ],
            ],
        ]);
    }

    protected function createMonitoringIndex()
    {
        $this->elastic->indices()->create([
            'index' => 'telescope_monitoring',
            'body' => [
                // 'settings' => [

                // ],
                'mappings' => [
                    '_doc' => [
                        '_source' => [
                            'enabled' => true
                        ],
                        'properties' => [
                            'entry_uuid' => [
                                'type' => 'keyword',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
