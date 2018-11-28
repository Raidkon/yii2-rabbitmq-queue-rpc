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
            $log  = '';
            $log .= print_r($e,1);
            $log .= "\n\n" . str_repeat('=',100) . "\n\n";
            $log .= print_r(['id' => $id,'params' => $params],1);
            $log .= "\n\n" . str_repeat('=',100) . "\n\n";
            file_put_contents('./rabbitmq-exception.txt',$log,FILE_APPEND);
            Yii::error($e);
            return 'deny';
        }
        catch (\Error $e){
            $log  = print_r($e,1);
            $log .= "\n\n" . str_repeat('=',100) . "\n\n";
            file_put_contents('./rabbitmq-error.txt',$log,FILE_APPEND);
            Yii::error($e);
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
