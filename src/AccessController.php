<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 26.11.2018
 * Time: 3:21
 */

namespace raidkon\yii2\ServerRpc;


use admin\components\WebController;
use raidkon\yii2\ServerRpc\interfaces\IRabbitMqAuthActions;
use Yii;

class AccessController extends WebController implements IRabbitMqAuthActions
{
    public $enableCsrfValidation = false;
    
    /** @var Server */
    public $server;
    
    public function runAction($id, $params = [])
    {
        try {
            $result = parent::runAction($id, $params);
            
            if ($result === false || $result === null){
                return 'deny';
            }
            if ($result !== 'deny' && $result !== 'allow'){
                return 'deny';
            }
            return $result;
        }
        catch (\Exception $e){
            Yii::error($e,Server::class);
            return 'deny';
        }
        catch (\Error $e){
            Yii::error($e,Server::class);
            return 'deny';
        }
    }
    
    public function actionUser($username,$password)
    {
        if ($this->server->checkAuthUser($username,$password)){
            return 'allow';
        }
        return 'deny';
    }
    
    public function actionVhost($username,$vhost,$ip)
    {
        return 'allow';
    }
    
    public function actionResource($username,$vhost,$resource,$name,$permission)
    {
        if ($this->server->checkResource($username,$vhost,$resource,$name,$permission)){
            return 'allow';
        }
        
        return 'deny';
    }
    
    public function actionTopic($username,$vhost,$resource,$name,$permission,$routing_key)
    {
        if ($this->server->checkTopic($username,$vhost,$resource,$name,$permission,$routing_key)){
            return 'allow';
        }
    
        return 'deny';
    }
}
