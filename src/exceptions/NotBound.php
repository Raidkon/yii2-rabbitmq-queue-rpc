<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 29.11.2018
 * Time: 22:32
 */

namespace raidkon\yii2\ServerRpc\exceptions;


class NotBound extends BaseException
{
    public $message = 'Var {{var}} not bound!';
}
