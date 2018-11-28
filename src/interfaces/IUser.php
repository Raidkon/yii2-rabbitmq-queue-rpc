<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 25.11.2018
 * Time: 5:26
 */

namespace raidkon\yii2\ServerRpc\interfaces;


use yii\web\IdentityInterface;

interface IUser extends IdentityInterface
{
    /** @return IUser */
    public static function rpcServerFind($user_id);
    /** @return bool */
    public function rpcServerValidPassword($password);
}
