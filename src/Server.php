<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 24.11.2018
 * Time: 18:32
 */

namespace raidkon\yii2\ServerRpc;


use common\components\ArrayHelper;
use Interop\Amqp\AmqpConsumer;
use Interop\Amqp\AmqpMessage;
use raidkon\yii2\ServerRpc\exceptions\ForbiddenException;
use raidkon\yii2\ServerRpc\exceptions\MessageIncorrectTypeException;
use raidkon\yii2\ServerRpc\exceptions\NotBound;
use raidkon\yii2\ServerRpc\exceptions\NotCallException;
use raidkon\yii2\ServerRpc\interfaces\ICommand;
use raidkon\yii2\ServerRpc\interfaces\IUser;
use Yii;
use yii\base\Application;
use yii\base\BaseObject;
use yii\base\BootstrapInterface;
use yii\base\InvalidConfigException;
use yii\console\Application as ConsoleApp;
use yii\web\Application as WebApp;
use yii\helpers\Inflector;

class Server extends BaseObject implements BootstrapInterface
{
    public $queueName = 'queueRpc';
    
    /** @var Queue */
    protected $_queue;
    
    public $accessMap = [];
    
    public $controllerMap = [];
    public $controllerNamespace = 'rpc\controllerNamespace';
    
    public $commandClass = CliCommand::class;
    public $commandOptions = [];
    
    public $controllerClass = AccessController::class;
    public $controllerOptions = [];
    
    public $userMap = [];
    
    public $userClass;
    public $stompHost = '';
    
    
    public function init()
    {
        parent::init();
    
        $comp = Yii::$app->get($this->queueName);
    
        if ($comp instanceof Queue){
            $this->_queue = $comp;
        } else {
            throw new InvalidConfigException('Component ' . $this->queueName . ' must be raidkon\yii2\ServerRpc\Queue');
        }
    }
    
    /**
     * @param array $message
     * @param string $route
     * @param array $params
     * @return string
     * @throws \Interop\Queue\Exception
     * @throws \Interop\Queue\InvalidDestinationException
     * @throws \Interop\Queue\InvalidMessageException
     */
    public function pushMessage($message,string $route,array $params = [])
    {
        if (!is_string($message)){
            $message = json_encode($message);
        }
        
        $route = preg_replace_callback('/{(?<var>[a-z0-9_]+?)}/is',function($matches) use ($params) {
            if (!key_exists($matches['var'],$params)){
                NotBound::throw(['var' => $matches['var']]);
            }
            return $params[$matches['var']];
        
        },$route);
    
        $messageId = uniqid('', true);
        $context = $this->_queue->getContext();
        
        $message = $context->createMessage($message);
        $message->setDeliveryMode(AmqpMessage::DELIVERY_MODE_PERSISTENT);
        $message->setMessageId($messageId);
        $message->setTimestamp(time());
        $message->setRoutingKey($route);
    
        $exchange = $context->createTopic($this->_queue->exchangeName);
    
        $producer = $context->createProducer();
        $producer->send($exchange,$message);
        return $messageId;
    }
    
    /**
     * @param $message string|array
     * @param $answerTo
     * @param $answerCorrelationId
     * @param null $fromUserId
     * @return string
     * @throws \Interop\Queue\Exception
     * @throws \Interop\Queue\InvalidDestinationException
     * @throws \Interop\Queue\InvalidMessageException
     */
    public function sendMessage($message,$answerTo,$answerCorrelationId,$fromUserId = null)
    {
        if (!$fromUserId){
            $fromUserId = ArrayHelper::getValue(Yii::$app,'user.id');
        }
        
        $messageId = uniqid('', true);
        
        $context = $this->_queue->getContext();
        
        if (!is_string($message) && !is_array($message)){
            MessageIncorrectTypeException::throw();
        }
        
        if (is_array($message)){
            $message = json_encode($message);
        }
        
        $message = $context->createMessage($message);
        $message->setDeliveryMode(AmqpMessage::DELIVERY_MODE_PERSISTENT);
        $message->setMessageId($messageId);
        $message->setTimestamp(time());
        $message->setProperty('correlation_id',$answerCorrelationId);
        $message->setProperty('user_id',$this->_queue->user);
        $message->setRoutingKey($answerTo);
        
        if ($fromUserId){
            $message->setProperty('real_user_id',$fromUserId);
        }
        
        $exchange = $context->createTopic('');
    
        $producer = $context->createProducer();
        $producer->send($exchange,$message);
        return $messageId;
    }
    
    /**
     * @throws InvalidConfigException
     */
    public function start()
    {
        $this->_queue->listen();
    }
    
    /**
     * @return string command id
     * @throws
     */
    protected function getCommandId()
    {
        foreach (Yii::$app->getComponents(false) as $id => $component) {
            if ($component === $this) {
                return Inflector::camel2id($id);
            }
        }
        throw new InvalidConfigException('Queue must be an application component.');
    }
    
    /**
     * Bootstrap method to be called during application bootstrap stage.
     * @param Application $app the application currently running
     * @throws InvalidConfigException
     */
    public function bootstrap($app)
    {
        if ($app instanceof WebApp) {
            $app->controllerMap[$this->getCommandId()] = [
                    'class'  => $this->controllerClass,
                    'server' => $this,
                ] + $this->commandOptions;
            
        } elseif ($app instanceof ConsoleApp) {
            $app->controllerMap[$this->getCommandId()] = [
                'class'  => $this->commandClass,
                'server' => $this,
            ] + $this->commandOptions;
        }
    }
    
    /**
     * @param string $user_id
     * @return IUser
     * @throws InvalidConfigException
     */
    public function findUser(string $user_id): IUser
    {
        if (!empty($this->userMap[$user_id])){
            $user_id = $this->userMap[$user_id];
        } elseif (empty($user_id)) {
            return null;
        }
        
        if (!is_subclass_of($this->userClass,  IUser::class)) {
            throw new InvalidConfigException('Class user must be ' . IUser::class);
        }
        
        return $this->userClass::rpcServerFind($user_id);
    }
    
    /**
     * @param $user
     * @param string $route
     * @param null $params
     * @return ICommand
     * @throws \Exception
     */
    public function createCmd($user, string $route, $params = null): ICommand
    {
        return new Command($this,$user,$route,$params);
    }
    
    /**
     * @param AmqpMessage $message
     * @param AmqpConsumer $consumer
     * @throws InvalidConfigException
     * @throws exceptions\BaseException
     * @throws \Exception
     */
    public function handleMessage(AmqpMessage $message, AmqpConsumer $consumer)
    {
        $user_id      = $message->getHeader('user_id');
        $real_user_id = $message->getProperty('real_user_id');
        
        if ($real_user_id && $user_id === $this->_queue->user){
            $user_id = $real_user_id;
        }
        
        $user = $this->findUser($user_id);
        
        $oldIdentity = Yii::$app->user->getIdentity(false);
        if ($user){
            Yii::$app->user->setIdentity($user);
        }
        $cmd  = $this->createCmd($user,$message->getRoutingKey(),$message->getBody());
    
        if (!$cmd->isCall()){
            Yii::$app->user->setIdentity($oldIdentity);
            NotCallException::create(['route' => $message->getRoutingKey()]);
        }
        
        if (!$cmd->checkAccess(ICommand::ACCESS_WRITE)){
            Yii::$app->user->setIdentity($oldIdentity);
            ForbiddenException::create(['name' => $cmd->getCheckAccessName()]);
        }
        
        $result = $cmd->call($message);
        Yii::$app->user->setIdentity($oldIdentity);
        return $result;
    }
    
    public function checkAuthUser($username,$password): bool
    {
        if ($this->_queue->user == $username){
            return $this->_queue->password == $password;
        }
        
        
        /** @var IUser $model */
        $model = $this->userClass;
        $model = $model::rpcServerFind($username);
        
        if (!$model){
            return false;
        }
        
        return $model->rpcServerValidPassword($password);
    }
    
    public function checkResource($username, $vhost, $resource, $name, $permission)
    {
        if ($this->_queue->user == $username){
            return true;
        }
    
        return true;
    }
    
    public function checkTopic($username, $vhost, $resource, $name, $permission,$routing_key)
    {
        if ($this->_queue->user == $username){
            return true;
        }
    
        return true;
    }
}
