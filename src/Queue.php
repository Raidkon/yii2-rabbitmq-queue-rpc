<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 15.11.2018
 * Time: 4:12
 */

namespace raidkon\yii2\ServerRpc;

use InvalidArgumentException;
use Yii;
use yii\base\InvalidConfigException;
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
    public $queueName     = 'server.queue.topic';
    public $exchangeName  = 'global.topic';
    public $user          = 'guest';
    public $password      = 'guest';
    public $port          = 5672;
    public $driver        = ExtendsQueue::ENQUEUE_AMQP_LIB;
    
    public $serverRpcComponentName = 'serverRpc';
    
    /**
     * Listens amqp-queue and runs new jobs.
     */
    public function listen()
    {
        $this->open();
        $this->setupBroker();
        
        $queue = $this->context->createQueue($this->queueName);
        $consumer = $this->context->createConsumer($queue);
        $this->context->subscribe($consumer, function (AmqpMessage $message, AmqpConsumer $consumer) {
            $comp = Yii::$app->get($this->serverRpcComponentName);
    
            if (!($comp instanceof Server)){
                throw new InvalidConfigException('Component ' . $this->queue . ' must be raidkon\yii2\ServerRpc\Queue');
            }
    
            try {
                if ($message->isRedelivered()) {
                    $consumer->acknowledge($message);
            
                    $this->redeliver($message);
            
                    return true;
                }
        
                if (!$comp->handleMessage($message,$consumer)) {
                    $this->redeliver($message);
                }
                $consumer->acknowledge($message);
            } catch (\Exception $error) {
                $consumer->acknowledge($message);
                var_dump($error->getMessage());
                var_dump($error->getTraceAsString());
                return true;
            } catch (\Error $error) {
                $consumer->acknowledge($message);
                var_dump($error->getMessage());
                var_dump($error->getTraceAsString());
                return true;
            }
            
            
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
        
        $exchange = $this->context->createTopic($this->exchangeName);
        $exchange->setType($this->exchangeType);
        $exchange->addFlag(AmqpTopic::FLAG_DURABLE);
        $this->context->declareTopic($exchange);
        $this->context->bind(new AmqpBind($queue, $exchange));
        
        $this->setupBrokerDone = true;
    }
}
