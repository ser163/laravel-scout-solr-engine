<?php

/** @noinspection PhpPossiblePolymorphicInvocationInspection */
/** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

namespace Scout\Solr\Engines;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Scout\Solr\Client;
use Scout\Solr\ClientInterface;
use Scout\Solr\Events\AfterSelect;
use Scout\Solr\Events\BeforeSelect;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Query\QueryInterface;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\QueryType\Select\Result\Document;
use Solarium\QueryType\Select\Result\Result;

/**
 * @mixin Client
 */
class SolrEngine extends Engine
{
    private ClientInterface $client;
    private Repository $config;
    private Dispatcher $events;

    public function __construct(ClientInterface $client, Repository $config, Dispatcher $events)
    {
        $this->client = $client;
        $this->config = $config;
        $this->events = $events;
    }

    public function update($models): ResultInterface
    {
        $this->client->setCore($models->first());

        $update = $this->client->createUpdate();
        $documents = $models->map(static function (Model $model) use ($update) {
            if (empty($searchableData = $model->toSearchableArray())) {
                /** @noinspection PhpInconsistentReturnPointsInspection */
                return;
            }

            return $update->createDocument(
                array_merge($searchableData, $model->scoutMetadata())
            );
        })->filter()->values()->all();

        $update->addDocuments($documents);
        $update->addCommit();

        return $this->client->update($update, $this->getEndpointFromConfig($models->first()->searchableAs()));
    }

    public function delete($models): void
    {
        $this->client->setCore($models->first());

        $delete = $this->client->createUpdate();
        $delete->addDeleteByIds(
            $models->map->getScoutKey()
                ->values()
                ->all()
        );
        $delete->addCommit();
        $this->client->update($delete, $this->getEndpointFromConfig($models->first()->searchableAs()));
    }

    public function search(Builder $builder): Result
    {
        return $this->performSearch($builder, array_filter([
            'whereIns' => $this->filtersWhereIns($builder),
            'limit' => $builder->limit,
        ]));
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, array_filter([
            'whereIns' => $this->filtersWhereIns($builder),
            'limit' => (int) $perPage,
            'offset' => ($page - 1) * $perPage,
        ]));
    }

    public function mapIds($results): SupportCollection
    {
        return collect($results)->map(static function (Document $document) {
            return $document->getFields()['id'];
        })->values();
    }

    /**
     * @param Builder $builder
     * @param Result $results
     * @param Model $model
     * @return Collection|void
     */
    public function map(Builder $builder, $results, $model)
    {
        if ($results->getNumFound() === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results->getDocuments())->map(static function (Document $document) {
            return $document->getFields()['id'];
        })->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds, false);
            })->sortBy(function ($model) use ($objectIdPositions) {
                return $objectIdPositions[$model->getScoutKey()];
            })->values();
    }

    /**
     * @param Builder $builder
     * @param Result $results
     * @param Model $model
     * @return LazyCollection|void
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        if ($results->getNumFound() === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = collect($results->getDocuments())->map(static function (Document $document) {
            return $document->getFields()['id'];
        })->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds, false);
            })->sortBy(function ($model) use ($objectIdPositions) {
                return $objectIdPositions[$model->getScoutKey()];
            })->values();
    }

    /**
     * @param Result $results
     * @return int
     */
    public function getTotalCount($results): int
    {
        return $results->getNumFound();
    }

    public function flush($model): void
    {
        $query = $this->client->setCore(new $model())->createUpdate();
        $query->addDeleteQuery('*:*');
        $query->addCommit();

        $this->client->update($query, $this->getEndpointFromConfig($model->searchableAs()));
    }

    public function createIndex($name, array $options = [])
    {
        $coreAdminQuery = $this->client->createCoreAdmin();

        $action = $coreAdminQuery->createCreate();
        $action->setCore($name);
        $action->setConfigSet($this->config->get('scout-solr.create.config_set'));

        $coreAdminQuery->setAction($action);
        return $this->client->coreAdmin($coreAdminQuery, $this->getEndpointFromConfig($name));
    }

    public function deleteIndex($name)
    {
        $coreAdminQuery = $this->client->createCoreAdmin();

        $action = $coreAdminQuery->createUnload();
        $action->setCore($name);
        $action->setDeleteIndex($this->config->get('scout-solr.unload.delete_index'));
        $action->setDeleteDataDir($this->config->get('scout-solr.unload.delete_data_dir'));
        $action->setDeleteInstanceDir($this->config->get('scout-solr.unload.delete_instance_dir'));

        $coreAdminQuery->setAction($action);
        return $this->client->coreAdmin($coreAdminQuery, $this->getEndpointFromConfig($name));
    }

    protected function performSearch(Builder $builder, array $options = []): Result
    {
        $this->client->setCore($builder->model);

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->client,
                $builder->query,
                $options
            );
        }

        $query = $this->client->createSelect();

        $this->filters($query, $builder);

        if (array_key_exists('whereIns', $options)) {
            $query->setQuery($options['whereIns']);
        } else {
            $query->setQuery($builder->query);
        }

        foreach ($builder->orders as $order) {
            $query->addSort($order['column'], $order['direction']);
        }

        $query->setStart($options['offset'] ?? 0)
            ->setRows($options['limit'] ?? $this->config->get('scout-solr.select.limit'));

        $this->events->dispatch(new BeforeSelect($query, $builder));

        $result = $this->client->select($query, $this->getEndpointFromConfig($builder->model->searchableAs()));

        $this->events->dispatch(new AfterSelect($result, $builder->model));

        return $result;
    }

    protected function filters(QueryInterface $query, Builder $builder)
    {
        if (is_array($builder->wheres)) {
            $wheres = collect($builder->wheres)->mapWithKeys(function ($value, $key) {
                return [$key => sprintf('%s:"%s"', $key, $value)];
            })->all();

            foreach ($wheres as $key => $value) {
                $query->createFilterQuery($key)->setQuery($value);
            }
        }
    }

    protected function filtersWhereIns(Builder $builder): string
    {
        $filters = new Collection();
        foreach ($builder->whereIns as $key => $values) {
            $filters->push(sprintf('%s:(%s)', $key, collect($values)->map(function ($value) {
                return $value;
            })->values()->implode(' OR ')));
        }

        return $filters->values()->implode(' AND ');
    }

    public function getEndpointFromConfig(string $name): ?Endpoint
    {
        if ($this->config->get('scout-solr.endpoints.' . $name) === null) {
            return null;
        }

        return new Endpoint($this->config->get('scout-solr.endpoints.' . $name));
    }

    /**
     * Dynamically call the Solr client instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        return $this->client->$method(...$parameters);
    }
}
