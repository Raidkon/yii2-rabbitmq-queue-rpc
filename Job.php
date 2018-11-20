<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 15.11.2018
 * Time: 3:44
 */

namespace raidkon\yii2\RabbitmqQueueRpc;

use yii\base\BaseObject;

class Job extends BaseObject implements \yii\queue\JobInterface
{
    public $amqp_message;
    public $amqp_consumer;
    public $command      = null;
    public $is_rpc       = false;
    public $params       = [];
    
    public static function isSet($name){
        return in_array($name,[
           'command',
           'is_rpc',
           'params'
        ]);
    }
    
    /**
     * @param \yii\queue\amqp_interop\Queue $queue which pushed and is handling the job
     */
    public function execute($queue)
    {
        var_dump($this->command);
        var_dump($this->is_rpc);
        var_dump($this->params);
    }
}