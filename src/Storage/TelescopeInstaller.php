<?php

namespace Jenky\TelescopeElasticsearch\Storage;

use Jenky\TelescopeElasticsearch\Contracts\Installer;
use Jenky\TelescopeElasticsearch\HasElasticsearchClient;

class TelescopeInstaller implements Installer
{
    use HasElasticsearchClient;

    /**
     * Install.
     *
     * @return void
     */
    public function install()
    {
        $this->createEntriesIndex();
        // $this->createTagsIndex();
        // $this->createMonitoringIndex();
    }

    /**
     * Uninstall.
     *
     * @return void
     */
    public function uninstall()
    {
        $indices = [
            'telescope_entries-'.date('Y.m.d'),
            // 'telescope_monitoring',
        ];

        foreach ($indices as $index) {
            $this->updateTelescopeAlias('remove');
            $this->elastic->indices()->delete(compact('index'));
        }
    }

    /**
     * Get the index name.
     *
     * @return string
     */
    public function indexName()
    {
        return 'telescope_entries-'.date('Y.m.d');
    }

    /**
     * Create telescope_entries index.
     *
     * @return void
     */
    protected function createEntriesIndex()
    {
        $this->elastic->indices()->create([
            'index' => $this->indexName(),
            'body' => [
                // 'settings' => [

                // ],
                'mappings' => [
                    '_doc' => [
                        '_source' => [
                            'enabled' => true,
                        ],
                        'properties' => [
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
                                'null_value' => true,
                            ],
                            'type' => [
                                'type' => 'keyword',
                            ],
                            'content' => [
                                'type' => 'object',
                                'dynamic' => false,
                            ],
                            'tags' => [
                                'type' => 'nested',
                                'dynamic' => false,
                                'properties' => [
                                    'raw' => [
                                        'type' => 'keyword',
                                    ],
                                    'name' => [
                                        'type' => 'keyword',
                                    ],
                                    'value' => [
                                        'type' => 'keyword',
                                    ],
                                ],
                            ],
                            'created_at' => [
                                'type' => 'date',
                                'format' => 'yyyy-MM-dd HH:mm:ss',
                            ],
                            '@timestamp' => [
                                'type' => 'date',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->updateTelescopeAlias('add');
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
                            'enabled' => true,
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

    /**
     * Add or remove telescope_entries-* alias.
     *
     * @param  string $action
     * @param  string $alias
     * @return void
     */
    protected function updateTelescopeAlias($action, $alias = 'telescope')
    {
        $this->elastic->indices()->updateAliases([
            'body' => [
                'actions' => [
                    [
                        $action => [
                            'index' => $this->indexName(),
                            'alias' => $alias,
                        ],
                    ],
                ],
            ],
        ]);
    }
}
