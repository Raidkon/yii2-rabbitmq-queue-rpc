<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 25.11.2018
 * Time: 17:58
 */

namespace raidkon\yii2\ServerRpc\interfaces;


use Interop\Amqp\AmqpMessage;
use raidkon\yii2\ServerRpc\Server;

interface ICommand
{
    public const ACCESS_WRITE = 'write';
    public const ACCESS_READ = 'read';
    public const ACCESS_CONFIGURE = 'configure';
    public const ACCESS_CALL = 'call';
    public const ACCESS_DEFAULT = 'default';
    
    public const RESOURCE_QUEUE    = 'queue';
    public const RESOURCE_EXCHANGE = 'exchange';
    public const RESOURCE_TOPIC    = 'topic';
    public const RESOURCE_MESSAGE  = 'topic';
    
    public function __construct(Server $server,IUser $user, string $route, $params = null);
    public function checkAccess($type,?string $resource = null): bool;
    public function isCall(): bool;
    public function call(AmqpMessage $message):bool;
    public function getCommandName():string;
    public function getActionName():?string;
    public function getCheckAccessName():string;
    public function initIdentity():bool;
    public function restoreIdentity():bool;
}
