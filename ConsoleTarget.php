<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 20.11.2018
 * Time: 2:14
 */

namespace raidkon\yii2\RabbitmqQueueRpc;

use yii\log\Target;

class ConsoleTarget extends Target
{
    public $exportInterval = 1;
    
    public $categories = ['raidkon\yii2\RabbitmqQueueRpc\\*'];
    
    /**
     * Exports log [[messages]] to a specific destination.
     * Child classes must implement this method.
     */
    public function export()
    {
        foreach ($this->messages as $message){
            echo date('d.m.Y H:i:s: '),print_r($message,1),PHP_EOL;
        }
    }
}
