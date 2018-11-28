<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 22.11.2018
 * Time: 0:32
 */

namespace raidkon\yii2\RabbitmqQueueRpc;



use Interop\Amqp\AmqpMessage;
use Interop\Queue\Exception;
use raidkon\yii2\RabbitmqQueueRpc\interfaces\IUser;
use raidkon\yii2\RabbitmqQueueRpc\interfaces\ICommand;
use Yii;
use yii\base\BaseObject;

class Command extends BaseObject implements ICommand
{
    public $server;
    public $user;
    public $params;
    public $routeParams = [];
    /** @var false|Controller */
    public $command;
    public $action;
    
    protected $_commandName;
    /** @var Server */
    protected $_server;
    protected $_user;
    
    /**
     * Command constructor.
     * @param Server $server
     * @param $user
     * @param string $route
     * @param null $params
     * @throws \Exception
     */
    public function __construct(Server $server, $user, string $route, $params = null)
    {
        parent::__construct([]);
        
        if ($user !== null && !$user instanceof IUser){
            throw new \Exception('User mus be interface \raidkon\yii2\RabbitmqQueueRpc\interfaces\IUser');
        }
        
        $this->_server = $server;
        $this->params  = $this->_parseParams($params);
        $this->command = $this->_parseRoute($route);
    }
    
    protected function _parseParams($params)
    {
        if (is_array($params)){
            return $params;
        }
        
        $params = @json_decode($params,true);
        
        if (!is_array($params)){
            return [];
        }
        
        return $params;
    }
    
    public function checkAccess($type): bool
    {
        $cmd = $this->getCheckAccessName();
        
        if (empty($this->_server->accessMap[$cmd])){
            return false;
        }
        $acl = $this->_server->accessMap[$cmd];
        if ($acl === true){
            return true;
        }
        if (empty($acl[$type])){
            return false;
        }
    }
    
    public function isCall(): bool
    {
        return (bool)$this->command;
    }
    
    /**
     * @param AmqpMessage $message
     * @return bool
     * @throws \Interop\Queue\Exception
     * @throws \yii\base\InvalidRouteException
     */
    public function call(AmqpMessage $message): bool
    {
        $result = $this->command->runAction($this->action, ['params' => $this->params,'routeParams' => $this->routeParams,'cmd' => $this,'message' => $message]);
        
        $reply_to = $message->getReplyTo();
        $reply_id = $message->getCorrelationId();
        
        if ($result === false) {
            if ($reply_to){
                $this->_server->sendMessage(['result' => true, 'errors' => ['method not return answer']],$reply_to,$reply_id);
            }
            return false;
        } elseif ($reply_to && $result === true) {
            $this->_server->sendMessage(['result' => true],$reply_to,$reply_id);
        } elseif ($reply_to && !is_array($result)) {
            $this->_server->sendMessage(['result' => true,'errors' => ['method return answer incrrect type']],$reply_to,$reply_id);
        } elseif ($reply_to) {
            if (!key_exists('result',$result)){
                $result['result'] = true;
            } else {
                $result['result'] = (bool)$result['result'];
            }
            $this->_server->sendMessage($result,$reply_to,$reply_id);
        }
        
        return true;
    }
    
    public function getCommandName(): string
    {
        return (string)$this->_commandName;
    }
    
    /**
     * @param $route
     * @return object|false
     * @throws \yii\base\InvalidConfigException
     * @throws \Exception
     */
    protected function _parseRoute($route)
    {
        $route    = preg_replace('/[^a-z0-9\.-]/is', '',$route);
        $routes   = explode('.', $route);
        $prevName = null;
        
        $filter = function($value) use (&$prevName) {
            $isn = is_numeric($value);
    
            if (!$isn){
                $prevName = $value;
                return true;
            }
            if($prevName){
                $this->routeParams[$prevName] = $value;
                $prevName = null;
            }
            return false;
        };
        
        $routes = array_filter($routes,$filter);
        
        if (!$routes){
            return false;
        }
        
        $cmd = implode('.',$routes);
        
        if (!empty($this->_server->controllerMap[$cmd])){
            $this->_commandName = $cmd;
            return $this->_createController($this->_server->controllerMap[$cmd],$routes[count($routes) - 1]);
        }
        
        $action = array_pop($routes);
        $cmd    = implode('.',$routes);
    
        if (!empty($this->_server->controllerMap[$cmd])){
            $this->action = $action;
            $this->_commandName = $cmd;
            return $this->_createController($this->_server->controllerMap[$cmd],$routes[count($routes) - 1]);
        }
        
        return false;
    }
    
    /**
     * @param $object
     * @return mixed
     * @throws \Exception
     */
    protected function _createController($class,$id)
    {
        $object = Yii::createObject($class,[$id,Yii::$app,'server' => $this->_server]);
        
        if (!($object instanceof Controller)) {
            throw new \Exception('Controller mus be interface:' . Controller::class);
        }
        
        return $object;
    }
    
    public function getCheckAccessName(): string
    {
        return implode('.',[$this->_commandName,$this->action]);
    }
}
