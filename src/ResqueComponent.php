<?php
namespace queues;

use yii;
use yii\base\Component;
use queues\Resque;
use queues\ResqueScheduler;
use queues\resque\ResqueLog;
use queues\resque\job\ResqueJobStatus;

class ResqueComponent extends Component
{
    public $server = 'localhost';

    /**
     * @var string Redis port number
     */
    public $port = '6379';

    /**
     * @var int Redis database index
     */
    public $database = 0;

    /**
     * @var string Redis username
     */
    public $user = '';

    /**
     * @var string Redis password auth
     */
    public $password = '';

    /**
     * @var string Redis key prefix
     */
    public $prefix = '';

    /**
     * @var array Redis other config
     */
    public $options = [];

    /**
     * Initializes the connection.
     */
    public function init()
    {
        parent::init();

        Resque::setBackend($this->buildDsn('redis'), $this->database);
        if ($this->password) {
            Resque::redis()->auth($this->password);
        }
        if ($this->prefix) {
            Resque::redis()->prefix($this->prefix);
        }
    }

    /**
     * Build a redis DSN string，which will be return one of the following formats:
     *
     * - host:port
     * - redis://user:pass@host:port/db?option1=val1&option2=val2
     * - tcp://user:pass@host:port/db?option1=val1&option2=val2
     *
     * @param  string|mixed $scheme Redis DSN string scheme
     * @return [type]         [description]
     */
    public function buildDsn($scheme = null)
    {
        if (null == $scheme) {
            return $this->server . ':' . $this->port;
        }
        $buildSchemes = ['redis', 'tcp'];
        if (!is_array($scheme) && in_array(strtolower($scheme), $buildSchemes)) {
            $options = http_build_query($this->options);
            $scheme = strtolower($scheme);
            return sprintf('%s://%s:%s@%s:%d/%d?%s', $scheme, $this->user, $this->password,
                    $this->server, $this->port, $this->database, $options);
        } else {
            throw new \Exception("Redis dsn scheme must is NULL or in (" .
                implode(',', $buildSchemes) . ').');
        }
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to execute the job.
     * @param array $args Any optional arguments that should be passed when the job is executed.
     *
     * @return string
     */
    public static function enqueueJob($queue, $class, $args = array(), $track_status = false)
    {
        return Resque::enqueue($queue, $class, $args, $track_status);
    }

    /**
     * Create a new scheduled job and save it to the specified queue.
     *
     * @param int $in Second count down to job.
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to execute the job.
     * @param array $args Any optional arguments that should be passed when the job is executed.
     *
     * @return string
     */
    public static function enqueueJobIn($in, $queue, $class, $args = array())
    {
        return ResqueScheduler::enqueueIn($in, $queue, $class, $args);
    }

    /**
     * Create a new scheduled job and save it to the specified queue.
     *
     * @param timestamp $at UNIX timestamp when job should be executed.
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to execute the job.
     * @param array $args Any optional arguments that should be passed when the job is executed.
     *
     * @return string
     */
    public static function enqueueJobAt($at, $queue, $class, $args = array())
    {
        return ResqueScheduler::enqueueAt($at, $queue, $class, $args);
    }

    /**
     * Serialization queue parameters
     * 
     * @param  array $args Any optional arguments that should be passed when the job is executed.
     * @return array       
     */
    private static function args2data($args = null)
    {
        $data = [];
        try {
            foreach ($args as $k => $v) {
                $data[$k] = serialize($v);
            }
        } catch (\Exception $e) {
            $data = serialize($args);
        }
        return $data;
    }

    /**
     * Get the queue file name according to the queue name
     * 
     * @param  string $name The name of the queue to place the job in.
     * @return string
     */
    private static function name2class($name)
    {
        $class_name = ucfirst($name);
        if (!self::endsWith($class_name, 'Worker'))
            $class_name .= 'Worker';
        $class = 'app\components\\' . $class_name;
        return $class;
    }

    /**
     * Determine whether the `sub` is the beginning of `str`
     *
     * @param  string $str string in characters
     * @param  string $sub sub string in characters
     * @return boolean
     */
    public static function beginsWith($str, $sub)
    {
        return (substr($str, 0, strlen($sub)) === $sub);
    }

    /**
     * Determine whether the `sub` is the end of `str`
     *
     * @param  string $str string in characters
     * @param  string $sub sub string in characters
     * @return boolean
     */
    public static function endsWith($str, $sub)
    {
        return (substr($str, strlen($str) - strlen($sub)) === $sub);
    }

    /**
     * Put a job in the background queue
     *
     * @param $name string Queue's name (requirements：Queue files must be in 
            the components directory and end of the file to Worker)
     * @param array $args The incoming queue data
     */
    public function put($name, $args = [])
    {
        return self::enqueueJob(
            $name, self::name2class($name), self::args2data($args), true);
    }

    /**
     * Put a job in the background queue (A certain time delay execution)
     * @param $name string Queue's name (requirements：Queue files must be in 
            the components directory and end of the file to Worker)
     * @param array $args The incoming queue data
     * @param int $in Seconds postponed
     */
    public function putIn($name, $args = [], $in = 0)
    {
        return self::enqueueJobIn(
            $in, $name, self::name2class($name), self::args2data($args));
    }

    /**
     * Put a job in the background queue (execution time execution)
     * @param $name string Queue's name (requirements：Queue files must be in 
            the components directory and end of the file to Worker)
     * @param array $args The incoming queue data
     * @param int /DateTime $at UNIX timestamp or datetime type value
     */
    public function putAt($name, $args = [], $at = 0)
    {
        return self::enqueueJobAt(
            $at, $name, self::name2class($name), self::args2data($args));
    }

    /**
     * Get job's args
     * @param $args object $this->args
     * @return array
     */
    public static function getArgs($args)
    {
        $result = [];
        try {
            foreach ($args as $k => $v) {
                $w = unserialize($v);
                $result[$k] = ($w !== false || $v == serialize(false)) ? $w : $v;
            }
        } catch (\Exception $e) {
            $result = unserialize($args);
        }
        return $result;
    }

    /**
     * Gets an instance of redis
     * @return object
     */
    public function redis()
    {
        return Resque::redis();
    }

    /**
     * Get all queue's name
     * @return array
     */
    public function getQueues()
    {
        return Resque::queues();
    }

    /**
     * Get queue size
     * @param  string $queue queue's name 
     * @return integer
     */
    public function size($queue)
    {
        return Resque::size($queue);
    }

    /**
     * Get job's Status
     * @param  string $id The job's id
     * @return integer
     */
    public function status($id)
    {
        $status = new ResqueJobStatus($id);
        return $status->get();
    }

    /**
     * Get all delayted jobs count
     * @return integer
     */
    public function getDelayedJobsCount()
    {
        return (int)Resque::redis()->zcard('delayed_queue_schedule');
    }

    /**
     * Gets an instance of ResqueLog
     * @param  boolean $level 
     * @return object
     */
    public function getLogger($level)
    {
        return new ResqueLog($level);
    }
}