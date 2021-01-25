<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal;

use Doctrine\Common\Annotations\AnnotationReader as DoctrineReader;
use Doctrine\Common\Annotations\Reader;
use JetBrains\PhpStorm\Pure;
use React\Promise\PromiseInterface;
use Spiral\Attributes\AnnotationReader;
use Spiral\Attributes\AttributeReader;
use Spiral\Attributes\Composite\SelectiveReader;
use Spiral\Attributes\ReaderInterface;
use Spiral\Goridge\Relay;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\Worker\Transport\Codec\CodecInterface;
use Temporal\Internal\Events\EventEmitterTrait;
use Temporal\Internal\Queue\ArrayQueue;
use Temporal\Internal\Queue\QueueInterface;
use Temporal\Internal\Repository\ArrayRepository;
use Temporal\Internal\Repository\RepositoryInterface;
use Temporal\Internal\Transport\Client;
use Temporal\Internal\Transport\ClientInterface;
use Temporal\Internal\Transport\Router;
use Temporal\Internal\Transport\RouterInterface;
use Temporal\Internal\Transport\Server;
use Temporal\Internal\Transport\ServerInterface;
use Temporal\Worker\Transport\Codec\JsonCodec;
use Temporal\Worker\Transport\Codec\ProtoCodec;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\LoopInterface;
use Temporal\Worker\Worker;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\Transport\Goridge;
use Temporal\Worker\Transport\HostConnectionInterface;
use Temporal\Worker\Transport\RoadRunner;
use Temporal\Worker\Transport\RPCConnectionInterface;

final class WorkerFactory implements WorkerFactoryInterface, LoopInterface
{
    use EventEmitterTrait;

    /**
     * @var string
     */
    private const ERROR_MESSAGE_TYPE = 'Received message type must be a string, but %s given';

    /**
     * @var string
     */
    private const ERROR_HEADERS_TYPE = 'Received headers type must be a string, but %s given';

    /**
     * @var string
     */
    private const ERROR_HEADER_NOT_STRING_TYPE = 'Header "%s" argument type must be a string, but %s given';

    /**
     * @var string
     */
    private const ERROR_QUEUE_NOT_FOUND = 'Cannot find a worker for task queue "%s"';

    /**
     * @var string
     */
    private const HEADER_TASK_QUEUE = 'taskQueue';

    /**
     * @var string
     */
    private const RESERVED_ANNOTATIONS = [
        'readonly',
    ];

    /**
     * @var DataConverterInterface
     */
    private DataConverterInterface $converter;

    /**
     * @var ReaderInterface
     */
    private ReaderInterface $reader;

    /**
     * @var RouterInterface
     */
    private RouterInterface $router;

    /**
     * @var RepositoryInterface<WorkerInterface>
     */
    private RepositoryInterface $queues;

    /**
     * @var CodecInterface
     */
    private CodecInterface $codec;

    /**
     * @var ClientInterface
     */
    private ClientInterface $client;

    /**
     * @var ServerInterface
     */
    private ServerInterface $server;

    /**
     * @var QueueInterface
     */
    private QueueInterface $responses;

    /**
     * @var RPCConnectionInterface
     */
    private RPCConnectionInterface $rpc;

    /**
     * @param DataConverterInterface $dataConverter
     * @param RPCConnectionInterface $rpc
     */
    public function __construct(DataConverterInterface $dataConverter, RPCConnectionInterface $rpc)
    {
        $this->converter = $dataConverter;
        $this->rpc = $rpc;

        $this->boot();
    }

    /**
     * @param DataConverterInterface|null $converter
     * @param RPCConnectionInterface|null $rpc
     * @return WorkerFactoryInterface
     */
    public static function create(
        DataConverterInterface $converter = null,
        RPCConnectionInterface $rpc = null
    ): WorkerFactoryInterface {
        return new self(
            $converter ?? DataConverter::createDefault(),
            $rpc ?? Goridge::create()
        );
    }

    /**
     * @return void
     */
    private function boot(): void
    {
        $this->reader = $this->createReader();
        $this->queues = $this->createTaskQueue();
        $this->router = $this->createRouter();
        $this->responses = $this->createQueue();
        $this->client = $this->createClient();
        $this->server = $this->createServer();
    }

    /**
     * @return ReaderInterface
     */
    private function createReader(): ReaderInterface
    {
        if (\interface_exists(Reader::class)) {
            foreach (self::RESERVED_ANNOTATIONS as $annotation) {
                DoctrineReader::addGlobalIgnoredName($annotation);
            }

            return new SelectiveReader(
                [
                    new AnnotationReader(),
                    new AttributeReader(),
                ]
            );
        }

        return new AttributeReader();
    }

    /**
     * @return RepositoryInterface<WorkerInterface>
     */
    private function createTaskQueue(): RepositoryInterface
    {
        return new ArrayRepository();
    }

    /**
     * @return RouterInterface
     */
    private function createRouter(): RouterInterface
    {
        $router = new Router();
        $router->add(new Router\GetWorkerInfo($this->queues));

        return $router;
    }

    /**
     * @return QueueInterface
     */
    private function createQueue(): QueueInterface
    {
        return new ArrayQueue();
    }

    /**
     * @return ClientInterface
     */
    #[Pure]
    private function createClient(): ClientInterface
    {
        return new Client($this->responses, $this);
    }

    /**
     * @return ServerInterface
     */
    private function createServer(): ServerInterface
    {
        return new Server($this->responses, \Closure::fromCallable([$this, 'onRequest']));
    }

    /**
     * {@inheritDoc}
     * @todo pass options
     */
    public function newWorker(string $taskQueue = self::DEFAULT_TASK_QUEUE): WorkerInterface
    {
        $worker = new Worker($taskQueue, $this, $this->rpc);
        $this->queues->add($worker);

        return $worker;
    }

    /**
     * @return ReaderInterface
     */
    public function getReader(): ReaderInterface
    {
        return $this->reader;
    }

    /**
     * @return ClientInterface
     */
    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    /**
     * @return QueueInterface
     */
    public function getQueue(): QueueInterface
    {
        return $this->responses;
    }

    /**
     * @return DataConverterInterface
     */
    public function getDataConverter(): DataConverterInterface
    {
        return $this->converter;
    }

    /**
     * {@inheritDoc}
     */
    public function run(HostConnectionInterface $host = null): int
    {
        $host ??= RoadRunner::create();
        $this->codec = $this->createCodec();

        while ($msg = $host->waitBatch()) {
            try {
                $host->send($this->dispatch($msg->messages, $msg->context));
            } catch (\Throwable $e) {
                $host->error($e);
            }
        }

        return 0;
    }

    /**
     * @return CodecInterface
     */
    private function createCodec(): CodecInterface
    {
        // todo: make it better
        switch ($_SERVER['RR_CODEC'] ?? null) {
            case 'protobuf':
                return new ProtoCodec($this->converter);
            default:
                return new JsonCodec($this->converter);
        }
    }

    /**
     * @param string $messages
     * @param array $headers
     * @return string
     */
    private function dispatch(string $messages, array $headers): string
    {
        $commands = $this->codec->decode($messages);

        foreach ($commands as $command) {
            if ($command instanceof RequestInterface) {
                $this->server->dispatch($command, $headers);
            } else {
                $this->client->dispatch($command);
            }
        }

        $this->tick();

        return $this->codec->encode($this->responses);
    }

    /**
     * @return void
     */
    public function tick(): void
    {
        $this->emit(LoopInterface::ON_SIGNAL);
        $this->emit(LoopInterface::ON_CALLBACK);
        $this->emit(LoopInterface::ON_QUERY);
        $this->emit(LoopInterface::ON_TICK);
    }

    /**
     * @param RequestInterface $request
     * @param array $headers
     * @return PromiseInterface
     */
    private function onRequest(RequestInterface $request, array $headers): PromiseInterface
    {
        if (!isset($headers[self::HEADER_TASK_QUEUE])) {
            return $this->router->dispatch($request, $headers);
        }

        $queue = $this->findTaskQueueOrFail(
            $this->findTaskQueueNameOrFail($headers)
        );

        return $queue->dispatch($request, $headers);
    }

    /**
     * @param string $taskQueueName
     * @return WorkerInterface
     */
    private function findTaskQueueOrFail(string $taskQueueName): WorkerInterface
    {
        $queue = $this->queues->find($taskQueueName);

        if ($queue === null) {
            throw new \OutOfRangeException(\sprintf(self::ERROR_QUEUE_NOT_FOUND, $taskQueueName));
        }

        return $queue;
    }

    /**
     * @param array $headers
     * @return string
     */
    private function findTaskQueueNameOrFail(array $headers): string
    {
        $taskQueue = $headers[self::HEADER_TASK_QUEUE];

        if (!\is_string($taskQueue)) {
            $error = \vsprintf(
                self::ERROR_HEADER_NOT_STRING_TYPE,
                [
                    self::HEADER_TASK_QUEUE,
                    \get_debug_type($taskQueue)
                ]
            );

            throw new \InvalidArgumentException($error);
        }

        return $taskQueue;
    }
}