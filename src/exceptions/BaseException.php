<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 25.11.2018
 * Time: 18:07
 */

namespace raidkon\yii2\RabbitmqQueueRpc\exceptions;


use Yii;

class BaseException extends \Exception
{
    public $message = 'Base rpc server error';
    
    public static function create($params = [])
    {
        $e = new static();
        $e->setParams($params);
        throw $e;
    }
    
    public static function throw($params = [])
    {
        static::create($params);
    }
    
    public function setParams($params)
    {
        foreach ($params as $key => $val){
            $this->{$key} = $val;
        }
        
        $this->message = Yii::t('app',$this->message,$params);
    }
}
