<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 26.11.2018
 * Time: 3:26
 */

namespace raidkon\yii2\ServerRpc\interfaces;


interface IRabbitMqAuthActions
{
    public function actionUser($username,$password);
}
