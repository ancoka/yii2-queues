About
===========
Yii2-queues is a redis implementation based Yii framework queue extension service. The code is slightly modified on the basis of Chris Boulton's [php-resque](https://github.com/chrisboulton/php-resque "php-resque a PHP Resque Worker"), using the PSR4 standard, adding namespace support, and inheriting the Yii2 component.

Requirements
------------
* PHP 5.3+
* Redis 2.2+
* Composer

Install
------------
The preferred way to do this is through the [composer](http://getcomposer.org/download/).

Directly use the composer command to install:

```
php composer.phar require --prefer-dist soyaf518/yii2-queues "*"
```

Or add the following to your project's "composer.json" file:

```
"soyaf518/yii2-queues": "*"
```
And running:

```
composer install
```

Configuration
-----
To use this extension, you have to configure the ResqueComponent class in your application configuration:

```
return [
	//...
	'components' => [
		'resque' => [
			'class' => 'queues\ResqueComponent',
			'server' => '127.0.0.1',
			'port' => 6379,
			'database' => 0,
			'user' => '',
			'password' => '',
			'options' => [
				'timeout' => '',
				'persistent' => '',
			],
		],
	]
];

```

Usage
-----

Once the extension is installed, simply use it in your code by  :

1. Queueing Jobs

	Jobs are queued as follows:

	```
	<?php
	$data = array(
			'name' => 'yii-queues'
		);
	
	# Real-time execution
	Yii::$app->resque->put('default', $data);
	
	# Delayed execution
	Yii::$app->resque->putIn('default', $data, 10);
	
	# Timing execution
	Yii::$app->resque->putAt('default', $data, 1486620571);

	```


2. Defining Jobs

	```
	class DefaultWorker
	{
	    public function setUp()
	    {
		    // ... Set up environment for this job
	    }
	
	    public function perform()
	    {
	        $data = Yii::$app->resque->getArgs($this->args);
	
	        // @todo deal with this data
	    }
	
	    public function tearDown()
	    {
	    	// ... Remove environment for this job
	    }
	}
	```

3. Workers
	
	To start a worker:
	
	```
	$ QUEUE=file_serve php bin/resque

	```
4. Running All Queues

	All queues are supported in the same manner and processed in alphabetical order:
	
	```
	$ QUEUE='*' bin/resque
	```
5. Running Multiple Workers
	
	Multiple workers can be launched simultaneously by supplying the COUNT environment variable:
	
	```
	$ COUNT=5 bin/resque
	```

Thanks
-----
* Chris Boulton： [php-resque](https://github.com/chrisboulton/php-resque "php-resque a PHP Resque Worker")




