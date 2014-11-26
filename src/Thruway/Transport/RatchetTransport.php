<?php

namespace Thruway\Transport;


use Ratchet\WebSocket\Version\RFC6455\Frame;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use Thruway\Message\Message;

/**
 * class RatchetTransport
 */
class RatchetTransport extends AbstractTransport
{

    /**
     * @var \Ratchet\ConnectionInterface
     */
    private $conn;

    /**
     * @var mixed
     */
    private $pingSeq;

    /**
     * @var array
     */
    private $pingRequests;

    /**
     * Constructor
     *
     * @param \Ratchet\ConnectionInterface $conn
     * @param \React\EventLoop\LoopInterface $loop
     */
    function __construct($conn, LoopInterface $loop)
    {
        $this->conn         = $conn;
        $this->pingSeq      = 1234;
        $this->pingRequests = [];
        $this->loop         = $loop;
    }

    /**
     * Send message
     *
     * @param \Thruway\Message\Message $msg
     */
    public function sendMessage(Message $msg)
    {
        $this->conn->send($this->getSerializer()->serialize($msg));
    }

    /**
     * Close transport
     */
    public function close()
    {
        $this->conn->close();
    }

    /**
     * Get transport details
     *
     * @return array
     */
    public function getTransportDetails()
    {
        $transportAddress = null;
        $transportAddress = $this->conn->remoteAddress;

        return [
            "type"             => "ratchet",
            "transportAddress" => $transportAddress
        ];
    }

    /**
     * Ping
     *
     * @param int $timeout
     * @return \React\Promise\Promise
     */
    public function ping($timeout = 10)
    {
        $payload = $this->pingSeq;

        $this->conn->send(new Frame($payload, true, Frame::OP_PING));

        $seq = $this->pingSeq;

        $this->pingSeq++;

        if ($timeout > 0) {
            $timer = $this->loop->addTimer($timeout, function () use ($seq) {
                if (isset($this->pingRequests[$seq])) {
                    $this->pingRequests[$seq]['deferred']->reject('timeout');
                    unset($this->pingRequests[$seq]);
                }

            });

            $deferred = new Deferred();

            $this->pingRequests[$seq] = [
                'seq'      => $seq,
                'deferred' => $deferred,
                'timer'    => $timer
            ];

            return $deferred->promise();
        }

    }

    /**
     * Handle on pong
     *
     * @param \Ratchet\WebSocket\Version\RFC6455\Frame $frame
     */
    public function onPong(Frame $frame)
    {
        $seq = $frame->getPayload();

        if (isset($this->pingRequests[$seq]) && isset($this->pingRequests[$seq]['deferred'])) {
            $this->pingRequests[$seq]['deferred']->resolve($seq);
            /* @var $timer \React\EventLoop\Timer\TimerInterface */
            $timer = $this->pingRequests[$seq]['timer'];
            $timer->cancel();

            unset($this->pingRequests[$seq]);
        }

    }
} 