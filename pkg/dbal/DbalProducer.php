<?php

namespace Enqueue\Dbal;

use Doctrine\DBAL\Types\Type;
use Interop\Queue\Exception;
use Interop\Queue\InvalidDestinationException;
use Interop\Queue\InvalidMessageException;
use Interop\Queue\PsrDestination;
use Interop\Queue\PsrMessage;
use Interop\Queue\PsrProducer;
use Ramsey\Uuid\Codec\OrderedTimeCodec;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactory;

class DbalProducer implements PsrProducer
{
    /**
     * @var int|null
     */
    private $priority;

    /**
     * @var int|float|null
     */
    private $deliveryDelay;

    /**
     * @var int|float|null
     */
    private $timeToLive;

    /**
     * @var DbalContext
     */
    private $context;

    /**
     * @var OrderedTimeCodec
     */
    private $uuidCodec;

    /**
     * @param DbalContext $context
     */
    public function __construct(DbalContext $context)
    {
        $this->context = $context;
        $this->uuidCodec = new OrderedTimeCodec((new UuidFactory())->getUuidBuilder());
    }

    /**
     * @param DbalDestination $destination
     * @param DbalMessage     $message
     */
    public function send(PsrDestination $destination, PsrMessage $message): void
    {
        InvalidDestinationException::assertDestinationInstanceOf($destination, DbalDestination::class);
        InvalidMessageException::assertMessageInstanceOf($message, DbalMessage::class);

        if (null !== $this->priority && null === $message->getPriority()) {
            $message->setPriority($this->priority);
        }
        if (null !== $this->deliveryDelay && null === $message->getDeliveryDelay()) {
            $message->setDeliveryDelay($this->deliveryDelay);
        }
        if (null !== $this->timeToLive && null === $message->getTimeToLive()) {
            $message->setTimeToLive($this->timeToLive);
        }

        $body = $message->getBody();
        $uuid = Uuid::uuid1();

        $publishedAt = null !== $message->getPublishedAt() ?
            $message->getPublishedAt() :
            (int) (microtime(true) * 10000)
        ;

        $dbalMessage = [
            'id' => $this->uuidCodec->encodeBinary($uuid),
            'human_id' => $uuid->toString(),
            'published_at' => $publishedAt,
            'body' => $body,
            'headers' => JSON::encode($message->getHeaders()),
            'properties' => JSON::encode($message->getProperties()),
            'priority' => $message->getPriority(),
            'queue' => $destination->getQueueName(),
        ];

        $delay = $message->getDeliveryDelay();
        if ($delay) {
            if (!is_int($delay)) {
                throw new \LogicException(sprintf(
                    'Delay must be integer but got: "%s"',
                    is_object($delay) ? get_class($delay) : gettype($delay)
                ));
            }

            if ($delay <= 0) {
                throw new \LogicException(sprintf('Delay must be positive integer but got: "%s"', $delay));
            }

            $dbalMessage['delayed_until'] = time() + (int) $delay / 1000;
        }

        $timeToLive = $message->getTimeToLive();
        if ($timeToLive) {
            if (!is_int($timeToLive)) {
                throw new \LogicException(sprintf(
                    'TimeToLive must be integer but got: "%s"',
                    is_object($timeToLive) ? get_class($timeToLive) : gettype($timeToLive)
                ));
            }

            if ($timeToLive <= 0) {
                throw new \LogicException(sprintf('TimeToLive must be positive integer but got: "%s"', $timeToLive));
            }

            $dbalMessage['time_to_live'] = time() + (int) $timeToLive / 1000;
        }

        try {
            $this->context->getDbalConnection()->insert($this->context->getTableName(), $dbalMessage, [
                'id' => Type::GUID,
                'published_at' => Type::INTEGER,
                'body' => Type::TEXT,
                'headers' => Type::TEXT,
                'properties' => Type::TEXT,
                'priority' => Type::SMALLINT,
                'queue' => Type::STRING,
                'time_to_live' => Type::INTEGER,
                'delayed_until' => Type::INTEGER,
            ]);
        } catch (\Exception $e) {
            throw new Exception('The transport fails to send the message due to some internal error.', null, $e);
        }
    }

    public function setDeliveryDelay(int $deliveryDelay = null): PsrProducer
    {
        $this->deliveryDelay = $deliveryDelay;

        return $this;
    }

    public function getDeliveryDelay(): ?int
    {
        return $this->deliveryDelay;
    }

    public function setPriority(int $priority = null): PsrProducer
    {
        $this->priority = $priority;

        return $this;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setTimeToLive(int $timeToLive = null): PsrProducer
    {
        $this->timeToLive = $timeToLive;

        return $this;
    }

    public function getTimeToLive(): ?int
    {
        return $this->timeToLive;
    }
}
