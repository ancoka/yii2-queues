<?php
namespace queues\resque;

use queues\Resque;
use queues\resque\ResqueEvent;
use queues\resque\ResqueFailure;
use queues\resque\ResqueStat;
use queues\resque\ResqueException;
use queues\resque\job\ResqueJobStatus;
use queues\resque\job\ResqueJobDontPerform;

/**
 * Resque job.
 *
 * @package  resque
 * @author   Chris Boulton <chris@bigcommerce.com>
 * @license  http://www.opensource.org/licenses/mit-license.php
 */
class ResqueJob
{
    /**
     * @var string The name of the queue that this job belongs to.
     */
    public $queue;

    /**
     * @var ResqueWorker Instance of the Resque worker running this job.
     */
    public $worker;

    /**
     * @var array Array containing details of the job.
     */
    public $payload;

    /**
     * @var object Instance of the class performing work for this job.
     */
    private $instance;

    /**
     * Instantiate a new instance of a job.
     *
     * @param string $queue The queue that the job belongs to.
     * @param array $payload array containing details of the job.
     */
    public function __construct($queue, $payload)
    {
        $this->queue = $queue;
        $this->payload = $payload;
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to execute the job.
     * @param array $args Any optional arguments that should be passed when the job is executed.
     * @param boolean $monitor Set to true to be able to monitor the status of a job.
     * @param string $id Unique identifier for tracking the job. Generated if not supplied.
     *
     * @return string
     */
    public static function create($queue, $class, $args = null, $monitor = false, $id = null)
    {
        if (is_null($id)) {
            $id = Resque::generateJobId();
        }

        if ($args !== null && !is_array($args)) {
            throw new \InvalidArgumentException('Supplied $args must be an array.');
        }
        Resque::push($queue, array(
            'class' => $class,
            'args'  => array($args),
            'id'    => $id,
            'queue_time' => microtime(true),
        ));

        if ($monitor) {
            ResqueJobStatus::create($id);
        }

        return $id;
    }

    /**
     * Find the next available job from the specified queue and return an
     * instance of ResqueJob for it.
     *
     * @param string $queue The name of the queue to check for a job in.
     * @return null|object Null when there aren't any waiting jobs, instance of ResqueJob when a job was found.
     */
    public static function reserve($queue)
    {
        $payload = Resque::pop($queue);
        if (!is_array($payload)) {
            return false;
        }

        return new ResqueJob($queue, $payload);
    }

    /**
     * Find the next available job from the specified queues using blocking list pop
     * and return an instance of ResqueJob for it.
     *
     * @param array             $queues
     * @param int               $timeout
     * @return null|object Null when there aren't any waiting jobs, instance of ResqueJob when a job was found.
     */
    public static function reserveBlocking(array $queues, $timeout = null)
    {
        $item = Resque::blpop($queues, $timeout);

        if (!is_array($item)) {
            return false;
        }

        return new ResqueJob($item['queue'], $item['payload']);
    }

    /**
     * Update the status of the current job.
     *
     * @param int $status Status constant from ResqueJobStatus indicating the current status of a job.
     */
    public function updateStatus($status)
    {
        if (empty($this->payload['id'])) {
            return;
        }

        $statusInstance = new ResqueJobStatus($this->payload['id']);
        $statusInstance->update($status);
    }

    /**
     * Return the status of the current job.
     *
     * @return int The status of the job as one of the ResqueJobStatus constants.
     */
    public function getStatus()
    {
        $status = new ResqueJobStatus($this->payload['id']);
        return $status->get();
    }

    /**
     * Get the arguments supplied to this job.
     *
     * @return array Array of arguments.
     */
    public function getArguments()
    {
        if (!isset($this->payload['args'])) {
            return array();
        }

        return $this->payload['args'][0];
    }

    /**
     * Get the instantiated object for this job that will be performing work.
     *
     * @return object Instance of the object that this job belongs to.
     */
    public function getInstance()
    {
        if (!is_null($this->instance)) {
            return $this->instance;
        }

        if (!class_exists($this->payload['class'])) {
            throw new ResqueException('Could not find job class ' .
                $this->payload['class'] . '.');
        }

        if (!method_exists($this->payload['class'], 'perform')) {
            throw new ResqueException('Job class ' .
                $this->payload['class'] . ' does not contain a perform method.');
        }

        $this->instance = new $this->payload['class'];
        $this->instance->job = $this;
        $this->instance->args = $this->getArguments();
        $this->instance->queue = $this->queue;
        return $this->instance;
    }

    /**
     * Actually execute a job by calling the perform method on the class
     * associated with the job with the supplied arguments.
     *
     * @return bool
     * @throws ResqueException When the job's class could not be found or it does not contain a perform method.
     */
    public function perform()
    {
        try {
            ResqueEvent::trigger('beforePerform', $this);

            $instance = $this->getInstance();
            if (method_exists($instance, 'setUp')) {
                $instance->setUp();
            }

            $instance->perform();

            if (method_exists($instance, 'tearDown')) {
                $instance->tearDown();
            }

            ResqueEvent::trigger('afterPerform', $this);
        } catch(ResqueJobDontPerform $e) {
            // beforePerform/setUp have said don't perform this job. Return.
            return false;
        }

        return true;
    }

    /**
     * Mark the current job as having failed.
     *
     * @param $exception
     */
    public function fail($exception)
    {
        ResqueEvent::trigger('onFailure', array(
            'exception' => $exception,
            'job' => $this,
        ));

        $this->updateStatus(ResqueJobStatus::STATUS_FAILED);
        ResqueFailure::create(
            $this->payload,
            $exception,
            $this->worker,
            $this->queue
        );
        ResqueStat::incr('failed');
        ResqueStat::incr('failed:' . $this->worker);
    }

    /**
     * Re-queue the current job.
     * @return string
     */
    public function recreate()
    {
        $status = new ResqueJobStatus($this->payload['id']);
        $monitor = false;
        if ($status->isTracking()) {
            $monitor = true;
        }

        return self::create($this->queue, $this->payload['class'], $this->getArguments(), $monitor);
    }

    /**
     * Generate a string representation used to describe the current job.
     *
     * @return string The string representation of the job.
     */
    public function __toString()
    {
        $name = array('Job{' . $this->queue .'}');
        if (!empty($this->payload['id'])) {
            $name[] = 'ID: ' . $this->payload['id'];
        }
        $name[] = $this->payload['class'];
        if (!empty($this->payload['args'])) {
            $name[] = json_encode($this->payload['args']);
        }
        return '(' . implode(' | ', $name) . ')';
    }
}