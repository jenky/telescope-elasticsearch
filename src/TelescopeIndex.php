<?php

namespace Jenky\TelescopeElasticsearch;

use Jenky\LaravelElasticsearch\Storage\Index;

class TelescopeIndex extends Index
{
    /**
     * Create new index instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->setConnection(config('telescope.storage.elasticsearch.connection'));
    }

    /**
     * The index name.
     *
     * @var string
     */
    protected $index = 'telescope';

    /**
     * {@inheritdoc}
     */
    public function properties(): array
    {
        return [
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
        ];
    }
}
