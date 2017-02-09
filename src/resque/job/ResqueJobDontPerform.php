<?php
namespace queues\resque\job;

/**
 * Exception to be thrown if a job should not be performed/run.
 *
 * @package  resque/job
 * @author   Chris Boulton <chris@bigcommerce.com>
 * @license  http://www.opensource.org/licenses/mit-license.php
 */
class ResqueJobDontPerform extends \Exception
{

}