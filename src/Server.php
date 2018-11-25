<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 24.11.2018
 * Time: 18:32
 */

namespace raidkon\yii2\RabbitmqQueueRpc;


use Interop\Amqp\AmqpConsumer;
use Interop\Amqp\AmqpMessage;
use raidkon\yii2\RabbitmqQueueRpc\exceptions\ForbiddenException;
use raidkon\yii2\RabbitmqQueueRpc\exceptions\NotCallException;
use raidkon\yii2\RabbitmqQueueRpc\interfaces\ICommand;
use raidkon\yii2\RabbitmqQueueRpc\interfaces\IUser;
use Yii;
use yii\base\Application;
use yii\base\BaseObject;
use yii\base\BootstrapInterface;
use yii\base\InvalidConfigException;
use yii\console\Application as ConsoleApp;
use yii\web\Application as WebApp;
use yii\helpers\Inflector;
use yii\web\ForbiddenHttpException;
use yii\web\IdentityInterface;

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
    
    
    public function init()
    {
        parent::init();
    
        $comp = Yii::$app->get($this->queueName);
    
        if ($comp instanceof Queue){
            $this->_queue = $comp;
        } else {
            throw new InvalidConfigException('Component ' . $this->queueName . ' must be raidkon\yii2\RabbitmqQueueRpc\Queue');
        }
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
        
        return $this->userClass::findOne($user_id);
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
        $user = $this->findUser($message->getHeader('user_id'));
        $oldIdentity = Yii::$app->user->getIdentity(false);
        $cmd  = $this->createCmd($user,$message->getRoutingKey(),$message->getBody());
    
    
    
        if (!$cmd->isCall()){
            Yii::$app->user->setIdentity($oldIdentity);
            NotCallException::create(['route' => $message->getRoutingKey()]);
        }
        
        if (!$cmd->checkAccess(ICommand::ACCESS_WRITE)){
            Yii::$app->user->setIdentity($oldIdentity);
            ForbiddenException::create(['name' => $cmd->getCheckAccessName()]);
        }
        
        $result = $cmd->call();
        Yii::$app->user->setIdentity($oldIdentity);
        return $result;
    }
    
    public function checkAuthUser($username,$password): bool
    {
        $log  = print_r(['cmd' => 'checkAuthUser','username' => $username,'password' => $password],1);
        $log .= "\n\n" . str_repeat('=',100) . "\n\n";
        file_put_contents('./rabbitmq.txt',$log,FILE_APPEND);
        
        if ($this->_queue->user == $username){
            return $this->_queue->password == $password;
        }
        
        
        /** @var IdentityInterface $model */
        $model = $this->userClass;
        $model = $model::findIdentityByAccessToken($password);
        
        if (!$model){
            return false;
        }
        
        return $model->getId() == $username;
    }
    
    public function checkResource($username, $vhost, $resource, $name, $permission)
    {
        $log  = print_r(['cmd' => 'checkResource','username' => $username,'vhost' => $vhost,'resource' => $resource,'name' => $name,'permission' => $permission],1);
        $log .= "\n\n" . str_repeat('=',100) . "\n\n";
        file_put_contents('./rabbitmq.txt',$log,FILE_APPEND);
        
        if ($this->_queue->user == $username){
            return true;
        }
    
        return true;
    }
    
    public function checkTopic($username, $vhost, $resource, $name, $permission,$routing_key)
    {
        $log  = print_r(['cmd' => 'checkTopic','username' => $username,'vhost' => $vhost,'resource' => $resource,'name' => $name,'permission' => $permission,'routing_key' => $routing_key],1);
        $log .= "\n\n" . str_repeat('=',100) . "\n\n";
        file_put_contents('./rabbitmq.txt',$log,FILE_APPEND);
    
    
        if ($this->_queue->user == $username){
            return true;
        }
    
        return true;
    }
}
