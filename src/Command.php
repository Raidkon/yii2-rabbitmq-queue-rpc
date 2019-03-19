<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 22.11.2018
 * Time: 0:32
 */

namespace raidkon\yii2\ServerRpc;

use Interop\Amqp\AmqpMessage;
use raidkon\yii2\ServerRpc\interfaces\IUser;
use raidkon\yii2\ServerRpc\interfaces\ICommand;
use Yii;
use yii\base\BaseObject;

class Command extends BaseObject implements ICommand
{
    public $params;
    public $routeParams = [];
    /** @var false|Controller */
    public $command;
    public $action;
    
    protected $_commandName;
    /** @var Server */
    protected $_server;
    protected $_user;
    protected $_safeIdentitys = [];
    
    /**
     * Command constructor.
     * @param Server $server
     * @param $user
     * @param string $route
     * @param null $params
     * @throws \Exception
     */
    public function __construct(Server $server,?IUser $user, string $route, $params = null)
    {
        parent::__construct([]);
        
        $this->_user   = $user;
        $this->_server = $server;
        $this->params  = $params?$this->_parseParams($params):[];
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
    
    public function checkAccess($type,?string $resource = null): bool
    {
        if ($type===ICommand::ACCESS_DEFAULT){
            return false;
        }
        $cmd = $this->getCheckAccessName();
        if (empty($this->_server->accessMap[$cmd])){
            return false;
        }
        $acl = $this->_server->accessMap[$cmd];
        if ($acl === true || $acl === false){
            return $acl;
        }
        if (!is_array($acl)){
            return $this->_checkAccessAcl($acl,$type,$resource);
        }
        if ($resource && in_array($resource,[ICommand::RESOURCE_QUEUE,ICommand::RESOURCE_TOPIC,ICommand::RESOURCE_EXCHANGE]) && key_exists($resource,$acl)){
            $acl = $acl[$resource];
        }
        if (!empty($acl[$type])){
            return $this->_checkAccessAcl($acl[$type],$type,$resource);
        }
        /** @todo Удалить в версии 1.0 */
        if ($type===ICommand::ACCESS_CALL && !key_exists(ICommand::ACCESS_CALL,$acl)){
            return $this->checkAccess(ICommand::ACCESS_WRITE,$resource);
        }
        if (key_exists(ICommand::ACCESS_DEFAULT,$acl)){
            return $this->_checkAccessAcl($acl[ICommand::ACCESS_DEFAULT],$type,$resource);
        }
        $acl = $this->_server->accessMap[$cmd];
        if (key_exists(ICommand::ACCESS_DEFAULT,$acl)){
            return $this->_checkAccessAcl($acl[ICommand::ACCESS_DEFAULT],$type,$resource);
        }
        return false;
    }
    
    protected function _checkAccessAcl($permisions,$type,?string $resource = null)
    {
        if ($permisions === true || $permisions === false){
            return $permisions;
        }
        if (!$permisions){
            return false;
        }
        $this->initIdentity();
        $permisions = is_array($permisions)?$permisions:[$permisions];
        $result = false;
        $params = [
            'routes'   => $this->routeParams,
            'params'   => $this->params,
            'access'   => $type,
            'resource' => $resource,
        ];
        foreach ($permisions as $permision){
            if (Yii::$app->user->can($permision,$params)){
                $result = true;
                break;
            }
        }
        $this->restoreIdentity();
        return $result;
    }
    
    public function isCall(): bool
    {
        return !!$this->command;
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
        if (substr($route,0,8) === 'amq.gen-'){
            $routes = ['_system_','amq','gen'];
        } elseif (substr($route,0,4) === 'amq.'){
            $routes = ['_system_','amq','other'];
        } elseif (substr($route,0,19) === 'stomp-subscription-'){
            $routes = ['_system_','stomp','subscription'];
        } elseif (substr($route,0,6) === 'stomp-'){
            $routes = ['_system_','stomp','other'];
        } else {
            $route = preg_replace('/[^a-z0-9\.-]/is', '', $route);
            $routes = explode('.', $route);
            $prevName = null;
    
            $filter = function ($value) use (&$prevName) {
                if (!is_numeric($value)) {
                    $prevName = $value;
                    return true;
                }
                if ($prevName) {
                    $this->routeParams[$prevName] = $value;
                    $prevName = null;
                }
                return false;
            };
    
            $routes = array_filter($routes, $filter);
    
            if (!$routes) {
                return false;
            }
        }
    
        $cmd = implode('.', $routes);
        $this->_commandName = $cmd;
        
        if (!empty($this->_server->controllerMap[$cmd])){
            $class = $this->_server->controllerMap[$cmd];
            return $class?$this->_createController($class,$routes[count($routes) - 1]):false;
        }
        
        $action = array_pop($routes);
        $cmd    = implode('.',$routes);
    
        if (!empty($this->_server->controllerMap[$cmd])){
            $this->action       = $action;
            $this->_commandName = $cmd;
            $class = $this->_server->controllerMap[$cmd];
            return $class?$this->_createController($this->_server->controllerMap[$cmd],$routes[count($routes) - 1]):false;
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
        return $this->_commandName . ($this->action?'.' . $this->action:'');
    }
    
    public function getActionName(): ?string
    {
        return $this->action;
    }
    
    public function initIdentity(): bool
    {
        if ($this->_user){
            $this->_safeIdentitys[] = Yii::$app->user->getIdentity(false);
            Yii::$app->user->setIdentity($this->_user);
            return true;
        }
        return false;
    }
    
    public function restoreIdentity(): bool
    {
        if ($this->_user) {
            $restore = array_pop($this->_safeIdentitys);
            if ($restore) {
                Yii::$app->user->setIdentity($restore);
                return true;
            }
        }
        return false;
    }
}
