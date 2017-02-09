<?php
namespace queues;

use \yii\BaseYii;

/**
 * This file part of ResqueComponent
 *
 * Autoloader for Resque library
 *
 * For license and full copyright information please see main package file
 * @package       yii2-queues
 */

class ResqueAutoloader
{
    /**
     * Registers ResqueAutoloader as an SPL autoloader.
     */
    public static function register()
    {
        spl_autoload_unregister(['Yii', 'autoload']);
        spl_autoload_register([new self,'autoload']);
        spl_autoload_register(['Yii', 'autoload'], true, true);
    }

    /**
     * Handles autoloading of classes.
     *
     * @param  string  $class  A class name.
     *
     * @return boolean Returns true if the class has been loaded
     */
    public static function autoload($class)
    {
        //yii2 advance
        if (file_exists(\yii\BaseYii::$app->basePath . '/../frontend/components')){
            $file= \yii\BaseYii::$app->basePath . '/../frontend/components';
            if (scandir($file)){
                foreach (scandir($file) as $filename) {
                   $path = $file . $filename;
                    if (is_file($path)) {
                        require_once($path);
                    }
                }
            }
        } else{
            //yii2 basic
            $file= \Yii::getAlias('@app').'/components/';
            if (scandir($file)){
                foreach (scandir($file) as $filename) {
                    $path = $file . $filename;
                    if (is_file($path)) {
                        require_once $path;
                    }
                }
            }
        }

        require_once(__DIR__ . '/src/resque/ResqueJob.php');
        require_once(__DIR__ . '/src/resque/ResqueEvent.php');
        require_once(__DIR__ . '/src/resque/ResqueRedis.php');
        require_once(__DIR__ . '/src/resque/ResqueWorker.php');
        require_once(__DIR__ . '/src/resque/ResqueStat.php');
        require_once(__DIR__ . '/src/resque/ResqueLog.php');
        require_once(__DIR__ . '/src/resque/Job/ResqueJobStatus.php');
        require_once(__DIR__ . '/src/resque/ResqueException.php');
    }
}