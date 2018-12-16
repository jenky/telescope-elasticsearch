<?php

namespace Jenky\TelescopeElasticsearch\Storage;

use Illuminate\Support\Collection;
use Illuminate\Support\Traits\ForwardsCalls;

class ElasticsearchResults
{
    use ForwardsCalls;

    /**
     * @var array
     */
    protected $raw = [];

    /**
     * @var \Illuminate\Support\Collection
     */
    protected $hits;

    /**
     * Create elasticsearch result instance.
     *
     * @param  array $raw
     * @return void
     */
    public function __construct(array $raw = [])
    {
        $this->raw = $raw;

        $this->hits = collect($raw['hits']['hits'] ?? []);
    }

    /**
     * Create a new results instance.
     *
     * @param  mixed  ...$arguments
     * @return static
     */
    public static function make(...$arguments)
    {
        return new static(...$arguments);
    }

    /**
     * Get took value.
     *
     * @return int
     */
    public function took()
    {
        return $this->raw['took'];
    }

    /**
     * Get timed_out value.
     *
     * @return bool
     */
    public function timedOut()
    {
        return $this->raw['timed_out'];
    }

    /**
     * Get _shards value.
     *
     * @return array
     */
    public function shards()
    {
        return $this->raw['_shards'];
    }

    /**
     * Get hits.
     *
     * @return \Illuminate\Support\Collection
     */
    public function hits()
    {
        return $this->hits;
    }

    /**
     * Get total hits.
     *
     * @return int
     */
    public function total()
    {
        return $this->raw['hits']['total'];
    }

    /**
     * Make dynamic calls into the collection.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->forwardCallTo($this->hits(), $method, $parameters);
    }
}
