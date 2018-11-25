<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 15.11.2018
 * Time: 3:44
 */

namespace raidkon\yii2\RabbitmqQueueRpc;

use common\models\user\User;
use Interop\Amqp\AmqpConsumer;
use Interop\Amqp\AmqpMessage;

use Yii;
use yii\base\BaseObject;
use yii\web\IdentityInterface;

/**
 * Class Job
 * @package raidkon\yii2\RabbitmqQueueRpc
 *
 * @property-read boolean $isRpc
 * @property-read string $rpcReplyTo
 * @property-read string $rpcCorrelationId
 * @property-read User $user
 * @property-read boolean $isAuth
 */
class Job extends BaseObject implements \yii\queue\JobInterface
{
    /** @var AmqpMessage */
    public $amqp_message;
    /** @var AmqpConsumer */
    public $amqp_consumer;
    
    protected $_isRpc = false;
    protected $_rpcReplyTo = null;
    protected $_rpcCorrelationId = null;
    
    protected $_route;
    /** @var ICommand */
    protected $_command;
    
    protected $_userId = null;
    protected $_user = null;
    protected $_isAuth = false;
    /** @var Queue */
    protected $_queue;
    
    public $params = [];
    
    public static function isSet($name)
    {
        return in_array($name, [
            'params'
        ]);
    }
    
    public function getRoute()
    {
        return $this->_route;
    }
    
    public function getCommand()
    {
        return $this->_command;
    }
    
    public function getIsRpc(): bool
    {
        return $this->_isRpc;
    }
    
    public function getRpcReplyRo(): string
    {
        return $this->_rpcReplyTo;
    }
    
    public function getRpcCorrelationId(): int
    {
        return $this->_rpcCorrelationId;
    }
    
    public function getUserId(): int
    {
        return $this->_userId;
    }
    
    public function getUser(): ?User
    {
        return $this->_user;
    }
    
    public function getIsAuth(): bool
    {
        return $this->_isAuth;
    }
    
    /**
     * @param Queue $queue which pushed and is handling the job
     */
    public function execute($queue): void
    {
        echo '!!!',getmypid(),'-',getmygid(),'-',getmyuid(),PHP_EOL;
        
        /*$this->_queue = $queue;
        $this->_initRpc();
        $this->_initUser();
        
        if ($this->_initCommand()) {
            $this->_command->call();
        } else {
            Yii::error($this->_command->errors,Queue::class);
        }*/
    }
    
    protected function _initRpc()
    {
        $this->_rpcReplyTo = $this->amqp_message->getReplyTo();
        $this->_rpcCorrelationId = $this->amqp_message->getCorrelationId();
        $this->_isRpc = !$this->_rpcReplyTo;
    }
    
    protected function _initUser(): void
    {
        $this->_userId = $this->amqp_message->getHeader('user_id', null);
        
        if (!$this->_userId) {
            return;
        }
        try {
            $this->_user = User::findOne($this->_userId);
        }
        catch (\Exception $e){
            Yii::error($e->getMessage(), Queue::class);
        }
        catch (\Error $e){
            Yii::error($e->getMessage(), Queue::class);
        }
        catch (\Throwable $e){
            Yii::error($e->getMessage(), Queue::class);
        }
        
        if (!$this->_user) {
            return;
        }
        
        Yii::info('user find: ' . $this->_user->getId(), Queue::class);
        
        $this->_isAuth = true;
        
        if ($this->_user instanceof IdentityInterface) {
            Yii::$app->user->setIdentity($this->_user);
        }
    }
    
    protected function _initCommand(): bool
    {
        $this->_route = $this->amqp_message->getRoutingKey();
        $this->_command = new ICommand($this->_route,$this,$this->_queue->commands,$this->_queue->modelBinds);
        return $this->_command->isCall();
    }
}
