<?php

/*
 * This file is part of the GraphAware Neo4j Client package.
 *
 * (c) GraphAware Limited <http://graphaware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GraphAware\Neo4j\Client;

use GraphAware\Common\Cypher\Statement;
use GraphAware\Common\Result\Record;
use GraphAware\Neo4j\Client\Connection\ConnectionManager;
use GraphAware\Neo4j\Client\Event\FailureEvent;
use GraphAware\Neo4j\Client\Event\PostRunEvent;
use GraphAware\Neo4j\Client\Event\PreRunEvent;
use GraphAware\Neo4j\Client\Exception\Neo4jException;
use GraphAware\Neo4j\Client\Result\ResultCollection;
use GraphAware\Neo4j\Client\Schema\Label;
use GraphAware\Neo4j\Client\Transaction\Transaction;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Client implements ClientInterface
{
    const NEOCLIENT_VERSION = '4.6.3';

    /**
     * @var ConnectionManager
     */
    protected $connectionManager;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    public function __construct(ConnectionManager $connectionManager, EventDispatcherInterface $eventDispatcher = null)
    {
        $this->connectionManager = $connectionManager;
        $this->eventDispatcher = null !== $eventDispatcher ? $eventDispatcher : new EventDispatcher();
    }

    /**
     * Run a Cypher statement against the default database or the database specified.
     *
     * @param $query
     * @param array|null  $parameters
     * @param string|null $tag
     * @param string|null $connectionAlias
     *
     * @throws \GraphAware\Neo4j\Client\Exception\Neo4jExceptionInterface
     *
     * @return \GraphAware\Common\Result\Result|null
     */
    public function run($query, $parameters = null, $tag = null, $connectionAlias = null)
    {
        $connection = $this->connectionManager->getConnection($connectionAlias);
        $params = null !== $parameters ? $parameters : [];
        $statement = Statement::create($query, $params, $tag);
        $this->eventDispatcher->dispatch(new PreRunEvent([$statement]), Neo4jClientEvents::NEO4J_PRE_RUN);

        try {
            $result = $connection->run($query, $parameters, $tag);
            $this->eventDispatcher->dispatch(new PostRunEvent(ResultCollection::withResult($result)), Neo4jClientEvents::NEO4J_POST_RUN);
        } catch (Neo4jException $e) {
            $event = new FailureEvent($e);
            $this->eventDispatcher->dispatch($event, Neo4jClientEvents::NEO4J_ON_FAILURE);

            if ($event->shouldThrowException()) {
                throw $e;
            }

            return;
        }

        return $result;
    }

    /**
     * @param string      $query
     * @param array|null  $parameters
     * @param string|null $tag
     *
     * @throws Neo4jException
     *
     * @return \GraphAware\Common\Result\Result
     */
    public function runWrite($query, $parameters = null, $tag = null)
    {
        return $this->connectionManager
            ->getMasterConnection()
            ->run($query, $parameters, $tag);
    }

    /**
     * @deprecated since 4.0 - will be removed in 5.0 - use <code>$client->runWrite()</code> instead
     *
     * @param string      $query
     * @param array|null  $parameters
     * @param string|null $tag
     *
     * @throws Neo4jException
     *
     * @return \GraphAware\Common\Result\Result
     */
    public function sendWriteQuery($query, $parameters = null, $tag = null)
    {
        return $this->runWrite($query, $parameters, $tag);
    }

    /**
     * @param string|null $tag
     * @param string|null $connectionAlias
     *
     * @return StackInterface
     */
    public function stack($tag = null, $connectionAlias = null)
    {
        return Stack::create($tag, $connectionAlias);
    }

    /**
     * @throws Neo4jException
     *
     * @return ResultCollection|null
     */
    public function runStack(StackInterface $stack)
    {
        $connectionAlias = $stack->hasWrites()
            ? $this->connectionManager->getMasterConnection()->getAlias()
            : $stack->getConnectionAlias();
        $pipeline = $this->pipeline(null, null, $stack->getTag(), $connectionAlias);

        foreach ($stack->statements() as $statement) {
            $pipeline->push($statement->text(), $statement->parameters(), $statement->getTag());
        }

        $this->eventDispatcher->dispatch(new PreRunEvent($stack->statements()), Neo4jClientEvents::NEO4J_PRE_RUN);

        try {
            $results = $pipeline->run();
            $this->eventDispatcher->dispatch(new PostRunEvent($results), Neo4jClientEvents::NEO4J_POST_RUN);
        } catch (Neo4jException $e) {
            $event = new FailureEvent($e);
            $this->eventDispatcher->dispatch($event, Neo4jClientEvents::NEO4J_ON_FAILURE);

            if ($event->shouldThrowException()) {
                throw $e;
            }

            return null;
        }

        return $results;
    }

    /**
     * @param string|null $connectionAlias
     *
     * @return Transaction
     */
    public function transaction($connectionAlias = null)
    {
        $connection = $this->connectionManager->getConnection($connectionAlias);
        $driverTransaction = $connection->getTransaction();

        return new Transaction($driverTransaction, $this->eventDispatcher);
    }

    /**
     * @param string|null $query
     * @param array|null  $parameters
     * @param string|null $tag
     * @param string|null $connectionAlias
     *
     * @return \GraphAware\Common\Driver\PipelineInterface
     */
    private function pipeline($query = null, $parameters = null, $tag = null, $connectionAlias = null)
    {
        $connection = $this->connectionManager->getConnection($connectionAlias);

        return $connection->createPipeline($query, $parameters, $tag);
    }

    /**
     * @param string|null $conn
     *
     * @return Label[]
     */
    public function getLabels($conn = null)
    {
        $connection = $this->connectionManager->getConnection($conn);
        $result = $connection->getSession()->run('CALL db.labels()');

        return array_map(function (Record $record) {
            return new Label($record->get('label'));
        }, $result->records());
    }

    /**
     * @deprecated since 4.0 - will be removed in 5.0 - use <code>$client->run()</code> instead
     *
     * @param string      $query
     * @param array|null  $parameters
     * @param string|null $tag
     * @param string|null $connectionAlias
     *
     * @return \GraphAware\Common\Result\Result
     */
    public function sendCypherQuery($query, $parameters = null, $tag = null, $connectionAlias = null)
    {
        return $this->connectionManager
            ->getConnection($connectionAlias)
            ->run($query, $parameters, $tag);
    }

    /**
     * @return ConnectionManager
     */
    public function getConnectionManager()
    {
        return $this->connectionManager;
    }

    /**
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }
}
