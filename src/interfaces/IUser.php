<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 25.11.2018
 * Time: 5:26
 */

namespace raidkon\yii2\RabbitmqQueueRpc\interfaces;

use yii\web\IdentityInterface;

interface IUser extends IdentityInterface
{
    public static function findOne($user_id);
}
