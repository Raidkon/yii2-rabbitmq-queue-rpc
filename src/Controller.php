<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 25.11.2018
 * Time: 19:03
 */

namespace raidkon\yii2\RabbitmqQueueRpc;


use Interop\Amqp\Impl\AmqpMessage;
use Yii;
use yii\base\InlineAction;
use yii\web\BadRequestHttpException;

class Controller extends \yii\web\Controller
{
    /** @var Server */
    public $server;
    
    public $enableCsrfValidation = false;
    
    /**
     * @param \yii\base\Action $action
     * @param array $params
     * @return array
     * @throws \ReflectionException
     */
    public function bindActionParams($action, $params)
    {
        if ($action instanceof InlineAction) {
            $method = new \ReflectionMethod($this, $action->actionMethod);
        } else {
            $method = new \ReflectionMethod($action, 'run');
        }
        
        $args = [];
        $missing = [];
        $actionParams = [];
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $params)) {
                $args[] = $actionParams[$name] = $params[$name];
                unset($params[$name]);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $actionParams[$name] = $param->getDefaultValue();
            } else {
                $missing[] = $name;
            }
        }
        
        if (!empty($missing)) {
            throw new BadRequestHttpException(Yii::t('yii', 'Missing required parameters: {params}', [
                'params' => implode(', ', $missing),
            ]));
        }
        
        $this->actionParams = $actionParams;
        
        return $args;
    }
}
