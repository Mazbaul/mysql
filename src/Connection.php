<?php

namespace Amp\Mysql;

use Amp\Deferred;
use Amp\Promise;
use Amp\Socket\Socket;
use function Amp\call;

final class Connection implements \Amp\Sql\Connection {
    const REFRESH_GRANT = 0x01;
    const REFRESH_LOG = 0x02;
    const REFRESH_TABLES = 0x04;
    const REFRESH_HOSTS = 0x08;
    const REFRESH_STATUS = 0x10;
    const REFRESH_THREADS = 0x20;
    const REFRESH_SLAVE = 0x40;
    const REFRESH_MASTER = 0x80;

    /** @var \Amp\Mysql\Internal\Processor */
    private $processor;

    /** @var \Amp\Deferred|null */
    private $busy;

    /**
     * @param \Amp\Socket\Socket $socket
     * @param \Amp\Mysql\ConnectionConfig $config
     *
     * @return \Amp\Promise
     */
    public static function connect(Socket $socket, ConnectionConfig $config): Promise {
        return call(function () use ($socket, $config) {
            $processor = new Internal\Processor($socket, $config);
            yield $processor->connect();
            return new self($processor);
        });
    }

    /**
     * @param \Amp\Mysql\Internal\Processor $processor
     */
    private function __construct(Internal\Processor $processor) {
        $this->processor = $processor;
    }

    /**
     * @return bool False if the connection has been closed.
     */
    public function isAlive(): bool {
        return $this->processor->isAlive();
    }

    /**
     * @return int Timestamp of the last time this connection was used.
     */
    public function lastUsedAt(): int {
        return $this->processor->lastDataAt();
    }

    public function isReady(): bool {
        return $this->processor->isReady();
    }

    public function setCharset(string $charset, string $collate = ""): Promise {
        return $this->processor->setCharset($charset, $collate);
    }

    public function close() {
        $processor = $this->processor;
        // Send close command if connection is not already in a closed or closing state
        if ($processor->isAlive()) {
            $processor->sendClose()->onResolve(static function () use ($processor) {
                $processor->close();
            });
        }
    }

    public function useDb(string $db): Promise {
        return $this->processor->useDb($db);
    }

    /**
     * @param int $subcommand int one of the self::REFRESH_* constants
     *
     * @return \Amp\Promise
     */
    public function refresh(int $subcommand): Promise {
        return $this->processor->refresh($subcommand);
    }

    public function query(string $query): Promise {
        return call(function () use ($query) {
            while ($this->busy) {
                yield $this->busy->promise();
            }

            $result = yield $this->processor->query($query);

            if ($result instanceof Internal\ResultProxy) {
                return new ResultSet($result);
            }

            if ($result instanceof CommandResult) {
                return $result;
            }

            throw new FailureException("Unrecognized result type");
        });
    }

    public function transaction(int $isolation = Transaction::COMMITTED): Promise {
        return call(function () use ($isolation) {
            switch ($isolation) {
                case Transaction::UNCOMMITTED:
                    yield $this->query("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");
                    break;

                case Transaction::COMMITTED:
                    yield $this->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
                    break;

                case Transaction::REPEATABLE:
                    yield $this->query("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
                    break;

                case Transaction::SERIALIZABLE:
                    yield $this->query("SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE");
                    break;

                default:
                    throw new \Error("Invalid transaction type");
            }

            yield $this->query("START TRANSACTION");

            $this->busy = new Deferred;

            $transaction = new Transaction($this->processor, $isolation);
            $transaction->onDestruct(function () {
                \assert($this->busy !== null);

                $deferred = $this->busy;
                $this->busy = null;
                $deferred->resolve();
            });

            return $transaction;
        });
    }

    public function ping(): Promise {
        return $this->processor->ping();
    }

    public function prepare(string $query): Promise {
        return call(function () use ($query) {
            while ($this->busy) {
                yield $this->busy->promise();
            }

            return $this->processor->prepare($query);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function execute(string $sql, array $params = []): Promise {
        return call(function () use ($sql, $params) {
            /** @var \Amp\Mysql\Statement $statment */
            $statment = yield $this->prepare($sql);
            return yield $statment->execute($params);
        });
    }

    public function __destruct() {
        $this->processor->unreference();
    }
}
