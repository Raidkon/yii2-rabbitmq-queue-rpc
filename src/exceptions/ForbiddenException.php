<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 25.11.2018
 * Time: 18:07
 */

namespace raidkon\yii2\ServerRpc\exceptions;


class ForbiddenException extends BaseException
{
    public $message = 'Forbidden command: {name}';
}
