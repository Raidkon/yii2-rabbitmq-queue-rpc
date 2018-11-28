<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 25.11.2018
 * Time: 18:12
 */

namespace raidkon\yii2\ServerRpc\exceptions;


class NotCallException extends BaseException
{
    public $message = 'Call command not found, to route: {route}';
}
