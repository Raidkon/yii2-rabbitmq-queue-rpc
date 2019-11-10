<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 24.11.2018
 * Time: 18:32
 */

namespace raidkon\yii2\ServerRpc;


use common\components\ArrayHelper;
use Exception;
use Interop\Amqp\AmqpConsumer;
use Interop\Amqp\AmqpMessage;
use Interop\Queue\InvalidDestinationException;
use Interop\Queue\InvalidMessageException;
use raidkon\yii2\ServerRpc\exceptions\MessageIncorrectTypeException;
use raidkon\yii2\ServerRpc\exceptions\NotBound;
use raidkon\yii2\ServerRpc\interfaces\ICommand;
use raidkon\yii2\ServerRpc\interfaces\IUser;
use Throwable;
use Yii;
use yii\base\Application;
use yii\base\BaseObject;
use yii\base\BootstrapInterface;
use yii\base\InvalidConfigException;
use yii\console\Application as ConsoleApp;
use yii\helpers\Inflector;
use yii\web\Application as WebApp;

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
    public $isLazy = true;
    public $authCacheTime = 0;
    
    
    public function init()
    {
        parent::init();
    
        $comp = Yii::$app->get($this->queueName);
    
        if ($comp instanceof Queue) {
            $this->_queue = $comp;
        } else {
            throw new InvalidConfigException('Component ' . $this->queueName . ' must be raidkon\yii2\ServerRpc\Queue');
        }
    }
    
    public function getComponentId(): string
    {
        foreach (Yii::$app->getComponents(false) as $id => $component) {
            if ($component === $this) {
                return $id;
            }
        }
        throw new InvalidConfigException('Queue must be an application component.');
    }
    
    /**
     * @param array $message
     * @param string $route
     * @param array $params
     * @return string
     * @throws \Interop\Queue\Exception
     * @throws InvalidDestinationException
     * @throws InvalidMessageException
     */
    public function pushMessage($message, string $route, array $params = [])
    {
        $messageId = uniqid('', true);
        
        if ($this->isLazy) {
            Yii::$app->queue->push(new Job([
                'messageId' => $messageId,
                'message' => $message,
                'route' => $route,
                'params' => $params,
                'componentId' => $this->getComponentId()
            ]));
            
            return $messageId;
        }
        return $this->pushMessageNow($messageId, $message, $route, $params);
    }
    
    public function pushMessageNow($messageId, $message, string $route, array $params = [])
    {
        if (!is_string($message)) {
            $message = json_encode($message);
        }
    
        $route = preg_replace_callback('/{(?<var>[a-z0-9_]+?)}/is', function ($matches) use ($params) {
            if (!key_exists($matches['var'], $params)) {
                NotBound::throw(['var' => $matches['var']]);
            }
            return $params[$matches['var']];
        
        }, $route);
        
        
        $context = $this->_queue->getContext();
    
        $message = $context->createMessage($message);
        $message->setDeliveryMode(AmqpMessage::DELIVERY_MODE_NON_PERSISTENT);
        $message->setMessageId($messageId);
        $message->setTimestamp(time());
        $message->setRoutingKey($route);
    
        $exchange = $context->createTopic($this->_queue->exchangeName);
    
        $producer = $context->createProducer();
        $producer->send($exchange, $message);
        return $messageId;
    }
    
    /**
     * @param $message string|array
     * @param $answerTo
     * @param $answerCorrelationId
     * @param null $fromUserId
     * @return string
     * @throws \Interop\Queue\Exception
     * @throws InvalidDestinationException
     * @throws InvalidMessageException
     */
    public function sendMessage($message, $answerTo, $answerCorrelationId, $fromUserId = null)
    {
        if (!$fromUserId) {
            $fromUserId = ArrayHelper::getValue(Yii::$app, 'user.id');
        }
        
        $messageId = uniqid('', true);
        
        $context = $this->_queue->getContext();
    
        if (!is_string($message) && !is_array($message)) {
            MessageIncorrectTypeException::throw();
        }
    
        if (is_array($message)) {
            $message = json_encode($message);
        }
        
        $message = $context->createMessage($message);
        $message->setDeliveryMode(AmqpMessage::DELIVERY_MODE_PERSISTENT);
        $message->setMessageId($messageId);
        $message->setTimestamp(time());
        $message->setProperty('correlation_id', $answerCorrelationId);
        $message->setProperty('user_id', $this->_queue->user);
        $message->setRoutingKey($answerTo);
    
        if ($fromUserId) {
            $message->setProperty('real_user_id', $fromUserId);
        }
        
        $exchange = $context->createTopic('');
    
        $producer = $context->createProducer();
        $producer->send($exchange, $message);
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
                    'class' => $this->controllerClass,
                    'server' => $this,
                ] + $this->commandOptions;
            
        } elseif ($app instanceof ConsoleApp) {
            $app->controllerMap[$this->getCommandId()] = [
                    'class' => $this->commandClass,
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
        if (!empty($this->userMap[$user_id])) {
            $user_id = $this->userMap[$user_id];
        } elseif (empty($user_id)) {
            return null;
        }
    
        if (!is_subclass_of($this->userClass, IUser::class)) {
            throw new InvalidConfigException('Class user must be ' . IUser::class);
        }
        
        if (!$user_id) {
            return null;
        }
        
        return $this->userClass::rpcServerFind($user_id);
    }
    
    /**
     * @param $user
     * @param string $route
     * @param null $params
     * @return ICommand
     * @throws Exception
     */
    public function createCmd($user, string $route, $params = null): ICommand
    {
        return new Command($this, $user, $route, $params);
    }
    
    /**
     * @param AmqpMessage $message
     * @param AmqpConsumer $consumer
     * @throws InvalidConfigException
     * @throws exceptions\BaseException
     * @throws Exception
     */
    public function handleMessage(AmqpMessage $message, AmqpConsumer $consumer)
    {
        $user_id = $safe_user_id = $message->getHeader('user_id');
        $real_user_id = $message->getProperty('real_user_id');
        $log = [
            'type' => 'handleMessage',
            'from_user_id' => $safe_user_id,
            'from_real_user_id' => $real_user_id,
            'route_key' => $message->getRoutingKey(),
            'message' => $message->getBody(),
            'reply_to' => $message->getReplyTo(),
            'correlation_id' => $message->getCorrelationId(),
        ];
        $info = function ($data, $sub = null) {
            Yii::info($data, static::class . '::message' . ($sub ? '::' . $sub : ''));
        };
        $warning = function ($data, $sub = null) {
            Yii::warning($data, static::class . '::message' . ($sub ? '::' . $sub : ''));
        };
        $info($log);
        if ($real_user_id && $user_id === $this->_queue->user) {
            $user_id = $real_user_id;
        }
        try {
            $user = $this->findUser($user_id);
        } catch (Throwable $throwable) {
            $user = null;
        }
        if ($user) {
            $log['user_id'] = $user->getId();
        }
        $info($log);
        $cmd = $this->createCmd($user, $message->getRoutingKey(), $message->getBody());
        $cmd->initIdentity();
        $log += [
            'command' => $cmd->getCommandName(),
            'action' => $cmd->getActionName(),
        ];
        if (!$cmd->isCall()) {
            $cmd->restoreIdentity();
            $warning($log + ['result' => false, 'reason' => 'cmd is not call'], 'result');
            return true;
        }
    
        if (!$cmd->checkAccess(ICommand::ACCESS_CALL, ICommand::RESOURCE_MESSAGE)) {
            $cmd->restoreIdentity();
            $warning($log + ['result' => false, 'reason' => 'not access'], 'result');
            return true;
        }
        
        $result = $cmd->call($message);
        
        if ($result === true) {
            $info($log + ['result' => $result, 'reason' => 'result from command'], 'result');
        } else {
            $warning($log + ['result' => $result, 'reason' => 'result from command'], 'result');
        }
        $cmd->restoreIdentity();
        return $result;
    }
    
    protected function _findUser(?string $username): ?IUser
    {
        /** @var IUser $model */
        $model = $this->userClass;
        return $model::rpcServerFind($username);
    }
    
    public function checkAuthUser($username, $password): bool
    {
        $cacheKey = [
            'type' => static::class,
            'user' => $username,
            'password' => $password
        ];
    
        $cache = null;
        
        if ($this->authCacheTime > 0) {
            $cache = Yii::$app->cache;
    
            $result = $cache->get($cacheKey);
    
            if ($result !== false) {
                return !!$result;
            }
        }
        
        
        $log = [
            'type' => 'checkAuthUser',
            'username' => $username,
            'password' => !empty($password) ? '*' . strlen($password) . '*' . Yii::$app->security->generatePasswordHash($password) . '*' : '*is_empty*',
        ];
    
        Yii::info($log, static::class . '::access');
        if ($this->_queue->user == $username) {
            $result = $this->_queue->password == $password;
            $cache && $cache->set($cacheKey, (int)$result, 60);
            Yii::info($log + ['result' => $result], static::class . '::access::result');
            return $result;
        }
        
        $model = $this->_findUser($username);
    
        if (!$model) {
            Yii::info($log + ['result' => false], static::class . '::access::result');
            $cache && $cache->set($cacheKey, 0, 60);
            return false;
        }
    
        $result = $model->rpcServerValidPassword($password);
        Yii::info($log + ['result' => $result], static::class . '::access::result');
        $cache && $cache->set($cacheKey, (int)$result, 60);
        return $result;
    }
    
    public function checkResource($username, $vhost, $resource, $name, $permission)
    {
        $log = [
            'exec_cmd' => 'yii server-rpc/resource "' . implode('" "', func_get_args()) . '"',
            'type' => 'checkResource',
            'username' => $username,
            'vhost' => $vhost,
            'resource' => $resource,
            'name' => $name,
            'permission' => $permission
        ];
        $info = function ($data = [], $sub = null) {
            Yii::info($data, static::class . '::access' . ($sub ? '::' . $sub : ''));
        };
        $info($log);
    
        if (!in_array($permission, [ICommand::ACCESS_WRITE, ICommand::ACCESS_CONFIGURE, ICommand::ACCESS_READ])) {
            $info($log + ['result' => false, 'reason' => 'permission not exists'], 'result');
            return false;
        }
        if (!in_array($resource, [ICommand::RESOURCE_TOPIC, ICommand::RESOURCE_EXCHANGE, ICommand::RESOURCE_QUEUE])) {
            $info($log + ['result' => false, 'reason' => 'resource not exists'], 'result');
            return false;
        }
        
        // if server connect, allow everything
        if ($this->_queue->user == $username) {
            $info($log + ['result' => true], 'result');
            return true;
        }
        
        $user = $this->_findUser($username);
    
        if (!$user) {
            $info($log + ['result' => false, 'reason' => 'user not exists'], 'result');
            return false;
        }
        
        $log['user_id'] = $user->getId();
    
        $cmd = $this->createCmd($user, $name);
        
        $log['command_name'] = $cmd->getCommandName();
        $log['command_action'] = $cmd->getActionName();
    
        if (!$cmd->checkAccess($permission, $resource)) {
            $info($log + ['result' => false, 'reason' => 'not access'], 'result');
            return false;
        }
    
    
        $info($log + ['result' => true], 'result');
        return true;
    }
    
    public function checkTopic($username, $vhost, $resource, $name, $permission, $routing_key)
    {
        $log = [
            'exec_cmd' => 'yii server-rpc/topic "' . implode('" "', func_get_args()) . '"',
            'type' => 'checkTopic',
            'username' => $username,
            'vhost' => $vhost,
            'resource' => $resource,
            'name' => $name,
            'permission' => $permission,
            'routing_key' => $routing_key,
        ];
        $info = function ($data = [], $sub = null) {
            Yii::info($data, static::class . '::access' . ($sub ? '::' . $sub : ''));
        };
        $info();
        if ($this->_queue->user == $username) {
            $info($log + ['result' => true], 'result');
            return true;
        }
        if (!in_array($permission, [ICommand::ACCESS_WRITE, ICommand::ACCESS_CONFIGURE, ICommand::ACCESS_READ])) {
            $info($log + ['result' => false, 'reason' => 'permission not exists'], 'result');
            return false;
        }
        if (!in_array($resource, [ICommand::RESOURCE_TOPIC])) {
            $info($log + ['result' => false, 'reason' => 'resource not exists'], 'result');
            return false;
        }
    
        $user = $this->_findUser($username);
    
        if (!$user) {
            $info($log + ['result' => false, 'reason' => 'user not exists'], 'result');
            return false;
        }
    
        $log['user_id'] = $user->getId();
    
        $cmd = $this->createCmd($user, $routing_key);
        
        $log['command'] = $cmd->getCommandName();
        $log['action'] = $cmd->getActionName();
    
        if (!$cmd->checkAccess($permission, $resource)) {
            $info($log + ['result' => false, 'reason' => 'not access'], 'result');
            return false;
        }
        $info($log + ['result' => true, 'reason' => 'default'], 'result');
        return true;
    }
}
