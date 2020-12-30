<?php

namespace Enqueue\Tests\Client\ConsumptionExtension;

use Enqueue\Client\ConsumptionExtension\DelayRedeliveredMessageExtension;
use Enqueue\Client\DriverInterface;
use Enqueue\Client\DriverSendResult;
use Enqueue\Client\Message;
use Enqueue\Consumption\Context\MessageReceived;
use Enqueue\Consumption\Result;
use Enqueue\Null\NullMessage;
use Enqueue\Null\NullQueue;
use Interop\Queue\Consumer;
use Interop\Queue\Context as InteropContext;
use Interop\Queue\Destination;
use Interop\Queue\Message as TransportMessage;
use Interop\Queue\Processor;
use Interop\Queue\Queue;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DelayRedeliveredMessageExtensionTest extends TestCase
{
    public function testCouldBeConstructedWithRequiredArguments()
    {
        new DelayRedeliveredMessageExtension($this->createDriverMock(), 12345);
    }

    public function testShouldSendDelayedMessageAndRejectOriginalMessage()
    {
        $queue = new NullQueue('queue');

        $originMessage = new NullMessage();
        $originMessage->setRedelivered(true);
        $originMessage->setBody('theBody');
        $originMessage->setHeaders(['foo' => 'fooVal']);
        $originMessage->setProperties(['bar' => 'barVal']);

        /** @var Message $delayedMessage */
        $delayedMessage = new Message();

        $driver = $this->createDriverMock();
        $driver
            ->expects(self::once())
            ->method('sendToProcessor')
            ->with(self::isInstanceOf(Message::class))
            ->willReturn($this->createDriverSendResult())
        ;
        $driver
            ->expects(self::once())
            ->method('createClientMessage')
            ->with(self::identicalTo($originMessage))
            ->willReturn($delayedMessage)
        ;

        $logger = $this->createLoggerMock();
        $logger
            ->expects(self::at(0))
            ->method('debug')
            ->with('[DelayRedeliveredMessageExtension] Send delayed message')
        ;
        $logger
            ->expects(self::at(1))
            ->method('debug')
            ->with(
                '[DelayRedeliveredMessageExtension] '.
                'Reject redelivered original message by setting reject status to context.'
            )
        ;

        $messageReceived = new MessageReceived(
            $this->createContextMock(),
            $this->createConsumerStub($queue),
            $originMessage,
            $this->createProcessorMock(),
            1,
            $logger
        );

        $this->assertNull($messageReceived->getResult());

        $extension = new DelayRedeliveredMessageExtension($driver, 12345);
        $extension->onMessageReceived($messageReceived);

        $result = $messageReceived->getResult();
        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame(Result::REJECT, $result->getStatus());
        $this->assertSame('A new copy of the message was sent with a delay. The original message is rejected', $result->getReason());

        $this->assertInstanceOf(Message::class, $delayedMessage);
        $this->assertEquals([
            'enqueue.redelivery_count' => 1,
        ], $delayedMessage->getProperties());
    }

    public function testShouldDoNothingIfMessageIsNotRedelivered()
    {
        $message = new NullMessage();

        $driver = $this->createDriverMock();
        $driver
            ->expects(self::never())
            ->method('sendToProcessor')
        ;

        $messageReceived = new MessageReceived(
            $this->createContextMock(),
            $this->createConsumerStub(new NullQueue('queue')),
            $message,
            $this->createProcessorMock(),
            1,
            new NullLogger()
        );

        $extension = new DelayRedeliveredMessageExtension($driver, 12345);
        $extension->onMessageReceived($messageReceived);

        $this->assertNull($messageReceived->getResult());
    }

    public function testShouldDoNothingIfMessageIsRedeliveredButResultWasAlreadySetOnContext()
    {
        $message = new NullMessage();
        $message->setRedelivered(true);

        $driver = $this->createDriverMock();
        $driver
            ->expects(self::never())
            ->method('sendToProcessor')
        ;

        $messageReceived = new MessageReceived(
            $this->createContextMock(),
            $this->createConsumerStub(new NullQueue('queue')),
            $message,
            $this->createProcessorMock(),
            1,
            new NullLogger()
        );
        $messageReceived->setResult(Result::ack());

        $extension = new DelayRedeliveredMessageExtension($driver, 12345);
        $extension->onMessageReceived($messageReceived);
    }

    /**
     * @return MockObject
     */
    private function createDriverMock(): DriverInterface
    {
        return $this->createMock(DriverInterface::class);
    }

    /**
     * @return MockObject
     */
    private function createContextMock(): InteropContext
    {
        return $this->createMock(InteropContext::class);
    }

    /**
     * @return MockObject
     */
    private function createProcessorMock(): Processor
    {
        return $this->createMock(Processor::class);
    }

    /**
     * @param mixed $queue
     *
     * @return MockObject
     */
    private function createConsumerStub(?Queue $queue = null): Consumer
    {
        $consumerMock = $this->createMock(Consumer::class);
        $consumerMock
            ->expects($this->any())
            ->method('getQueue')
            ->willReturn($queue ?? new NullQueue('queue'))
        ;

        return $consumerMock;
    }

    /**
     * @return MockObject
     */
    private function createLoggerMock(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    private function createDriverSendResult(): DriverSendResult
    {
        return new DriverSendResult(
            $this->createMock(Destination::class),
            $this->createMock(TransportMessage::class)
        );
    }
}
