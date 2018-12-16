<?php

use Illuminate\Database\Migrations\Migration;
use Jenky\TelescopeElasticsearch\HasElasticsearchClient;

class CreateTelescopeIndex extends Migration
{
    use HasElasticsearchClient;

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->elastic->indices()->create([
            'index' => 'telescope',
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
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->elastic->indices()->delete(['index' => 'telescope']);
    }
}
