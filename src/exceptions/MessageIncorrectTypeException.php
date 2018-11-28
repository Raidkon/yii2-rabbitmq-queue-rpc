<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 29.11.2018
 * Time: 0:24
 */

namespace raidkon\yii2\ServerRpc\exceptions;


class MessageIncorrectTypeException extends BaseException
{
    public $message = 'Message incorrect type, allow only string or array';
}
