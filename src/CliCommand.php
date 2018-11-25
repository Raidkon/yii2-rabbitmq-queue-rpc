<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 24.11.2018
 * Time: 18:53
 */

namespace raidkon\yii2\RabbitmqQueueRpc;


use Yii;
use yii\console\Controller;

class CliCommand extends Controller
{
    /** @var Server */
    public $server;
    
    public $verbose;
    
    public function actionStart()
    {
        $this->server->start();
    }
    
    public function actionTest()
    {
        var_dump(empty(true));
    }
}
