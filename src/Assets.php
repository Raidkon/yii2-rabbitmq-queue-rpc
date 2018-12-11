<?php
/**
 * Created by PhpStorm.
 * User: Raidkon
 * Date: 03.12.2018
 * Time: 22:20
 */

namespace raidkon\yii2\ServerRpc;


use yii\web\AssetBundle;

class Assets extends AssetBundle
{
    //public $basePath = __DIR__ . DIRECTORY_SEPARATOR . 'assets';
    //public $baseUrl = '@web';
    public $sourcePath = __DIR__ . DIRECTORY_SEPARATOR . 'assets';
    
    public $js = [
        'js/stomp.js',
        'js/server-rpc.js',
    ];
}
