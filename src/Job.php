<?php


namespace raidkon\yii2\ServerRpc;

use Yii;
use yii\queue\JobEvent;
use yii\queue\JobInterface;
use yii\queue\Queue;
use yii\queue\RetryableJobInterface;

class Job extends JobEvent implements JobInterface, RetryableJobInterface
{
    public $messageId;
    public $message;
    public $route;
    public $params;
    public $componentId;
    
    /**
     * @param Queue $queue which pushed and is handling the job
     */
    public function execute($queue)
    {
        $a = 1;
        
        /** @var Server $component */
        if (!$component = Yii::$app->get($this->componentId, false)) {
            return;
        }
        
        $component->pushMessageNow(
            $this->messageId,
            $this->message,
            $this->route,
            $this->params
        );
    }
    
    /**
     * @return int time to reserve in seconds
     */
    public function getTtr()
    {
        return 20;
    }
    
    /**
     * @param int $attempt number
     * @param \Exception|\Throwable $error from last execute of the job
     * @return bool
     */
    public function canRetry($attempt, $error)
    {
        return false;
    }
}
