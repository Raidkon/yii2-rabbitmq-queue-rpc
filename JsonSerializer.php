<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 15.11.2018
 * Time: 3:10
 */

namespace raidkon\yii2\RabbitmqQueueRpc;


use yii\base\BaseObject;
use yii\queue\serializers\SerializerInterface;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\Json;

class JsonSerializer extends BaseObject implements SerializerInterface
{
    
    /**
     * @var string
     */
    public $class = Job::class;
    /**
     * @var int
     */
    public $options = 0;
    
    
    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function serialize($job)
    {
        return Json::encode($this->toArray($job), $this->options);
    }
    
    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function unserialize($serialized)
    {
        return $this->fromArray(Json::decode($serialized));
    }
    
    /**
     * @param mixed $data
     * @return array|mixed
     * @throws InvalidConfigException
     */
    protected function toArray($data)
    {
        if (is_object($data)) {
            foreach (get_object_vars($data) as $property => $value) {
                $result[$property] = $this->toArray($value);
            }
            
            return $result;
        }
        
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->toArray($value);
            }
            
            return $result;
        }
        
        return $data;
    }
    
    /**
     * @param array $data
     * @return mixed
     * @throws InvalidConfigException
     */
    protected function fromArray($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        
        $config = ['class' => $this->class,'params' => $data];
        return Yii::createObject($config);
    }
}
