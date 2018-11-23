<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 15.11.2018
 * Time: 4:12
 */

namespace raidkon\yii2\RabbitmqQueueRpc;


use common\models\user\User;
use InvalidArgumentException;
use Yii;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\queue\amqp_interop\Queue as ExtendsQueue;
use Interop\Amqp\AmqpConsumer;
use Interop\Amqp\AmqpMessage;
use Interop\Amqp\AmqpQueue;
use Interop\Amqp\AmqpTopic;
use Interop\Amqp\Impl\AmqpBind;
use yii\queue\ExecEvent;
use yii\queue\JobInterface;

class Queue extends ExtendsQueue
{
    public $exchangeType  = AmqpTopic::TYPE_TOPIC;
    public $exchangeBinds = ['#'];
    public $serializer    = JsonSerializer::class;
    public $queueName     = 'server.queue.topic';
    public $exchangeName  = 'global.topic';
    public $user          = 'guest';
    public $password      = 'guest';
    public $port          = 5672;
    public $driver        = ExtendsQueue::ENQUEUE_AMQP_LIB;
    public $commands      = [
        'system' => System::class
    ];
    
    public $modelBinds = [
        'user' => User::class
    ];
    
    /**
     * Listens amqp-queue and runs new jobs.
     */
    public function listen()
    {
        $this->open();
        $this->setupBroker();
    
        Yii::info('run listen',Queue::class);
        
        $queue = $this->context->createQueue($this->queueName);
        $consumer = $this->context->createConsumer($queue);
        $this->context->subscribe($consumer, function (AmqpMessage $message, AmqpConsumer $consumer) {
            if ($message->isRedelivered()) {
                $consumer->acknowledge($message);
                
                $this->redeliver($message);
                
                return true;
            }
            
            $ttr = $message->getProperty(self::TTR);
            $attempt = $message->getProperty(self::ATTEMPT, 1);
    
            try {
                $this->handleMessageAMQP($message->getMessageId(), $message->getBody(), $ttr, $attempt, $message, $consumer);
            }
            catch (\Error $e){
                Yii::getLogger()->log($e,Logger::LEVEL_ERROR,Queue::class);
            }
            catch (\Exception $e){
                Yii::getLogger()->log($e,Logger::LEVEL_ERROR,Queue::class);
            }
            catch (\Throwable $e){
                Yii::getLogger()->log($e,Logger::LEVEL_ERROR,Queue::class);
            }
            
            $consumer->acknowledge($message);
            
            return true;
        });
        
        $this->context->consume();
    }
    
    protected function setupBroker()
    {
        if ($this->setupBrokerDone) {
            return;
        }
    
        $queue = $this->context->createQueue($this->queueName);
        $queue->addFlag(AmqpQueue::FLAG_DURABLE);
        $queue->setArguments(['x-max-priority' => $this->maxPriority]);
        $this->context->declareQueue($queue);
    
        $topic = $this->context->createTopic($this->exchangeName);
        $topic->setType($this->exchangeType);
        $topic->addFlag(AmqpTopic::FLAG_DURABLE);
        $this->context->declareTopic($topic);
        
        foreach ($this->exchangeBinds as $bindName){
            $this->context->bind(new AmqpBind($queue,$topic,$bindName));
        }
    
        $this->context->bind(new AmqpBind($queue, $topic));
    
        $this->setupBrokerDone = true;
    }
    
    /**
     * @inheritdoc
     */
    /**
     * @param string $id of a job message
     * @param string $message
     * @param int $ttr time to reserve
     * @param int $attempt number
     * @return bool
     */
    protected function handleMessageAMQP($id, $message, $ttr, $attempt,AmqpMessage $AMQPmessage, AmqpConsumer $AMQPconsumer)
    {
        $log   = [];
        $log['date']    = date('d.m.Y h:i:s');
        $log['message'] = $message;
        $log['id']      = $AMQPmessage->getMessageId();
        $log['prop']    = $AMQPmessage->getProperties();
        $log['route']   = $AMQPmessage->getRoutingKey();
        
        //Yii::info($log,Queue::class);
        
        $job = $this->serializer->unserialize($message);
        if (!($job instanceof JobInterface)) {
            $dump = VarDumper::dumpAsString($job);
            throw new InvalidArgumentException("Job $id must be a JobInterface instance instead of $dump.");
        }
        
        if ($job instanceof Job){
            $job->amqp_message  = $AMQPmessage;
            $job->amqp_consumer = $AMQPconsumer;
        }
        
        $event = new ExecEvent([
            'id' => $id,
            'job' => $job,
            'ttr' => $ttr,
            'attempt' => $attempt,
        ]);
        $this->trigger(self::EVENT_BEFORE_EXEC, $event);
        if ($event->handled) {
            return true;
        }
        
        try {
            $event->job->execute($this);
        } catch (\Exception $error) {
            return $this->handleError($event->id, $event->job, $event->ttr, $event->attempt, $error);
        } catch (\Throwable $error) {
            return $this->handleError($event->id, $event->job, $event->ttr, $event->attempt, $error);
        }
        $this->trigger(self::EVENT_AFTER_EXEC, $event);
        
        return true;
    }
    
    public function brokerPush($message,$routeKey,$ttr = null)
    {
        return $this->pushMessage($message,$ttr,null,null,$routeKey);
    }
    
    /**
     * @param $payload
     * @param $ttr
     * @param $delay
     * @param $priority
     * @param null $routeKey
     * @return string
     * @throws \Interop\Queue\DeliveryDelayNotSupportedException
     * @throws \Interop\Queue\Exception
     * @throws \Interop\Queue\InvalidDestinationException
     * @throws \Interop\Queue\InvalidMessageException
     * @throws \Interop\Queue\PriorityNotSupportedException
     */
    protected function pushMessage($payload, $ttr, $delay, $priority,$routeKey = null)
    {
        $this->open();
        $this->setupBroker();
        
        $topic = $this->context->createTopic($this->exchangeName);
        
        $message = $this->context->createMessage($payload);
        $message->setDeliveryMode(AmqpMessage::DELIVERY_MODE_PERSISTENT);
        $message->setMessageId(uniqid('', true));
        $message->setTimestamp(time());
        $message->setProperty(self::ATTEMPT, 1);
        $message->setProperty(self::TTR, $ttr);
        $message->setRoutingKey($routeKey);
        
        $producer = $this->context->createProducer();
        
        if ($delay) {
            $message->setProperty(self::DELAY, $delay);
            $producer->setDeliveryDelay($delay * 1000);
        }
        
        if ($priority) {
            $message->setProperty(self::PRIORITY, $priority);
            $producer->setPriority($priority);
        }
        
        $producer->send($topic, $message);
        
        return $message->getMessageId();
    }
}
