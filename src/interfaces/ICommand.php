<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 25.11.2018
 * Time: 17:58
 */

namespace raidkon\yii2\RabbitmqQueueRpc\interfaces;


use Interop\Amqp\AmqpMessage;
use raidkon\yii2\RabbitmqQueueRpc\Server;

interface ICommand
{
    public const ACCESS_WRITE = 'write';
    public const ACCESS_READ = 'read';
    public const ACCESS_CONFIGURE = 'configure';
    
    public function __construct(Server $server, $user, string $route, $params = null);
    public function checkAccess($type): bool;
    public function isCall(): bool;
    public function call(AmqpMessage $message):bool;
    public function getCommandName():string;
    public function getCheckAccessName():string;
}
