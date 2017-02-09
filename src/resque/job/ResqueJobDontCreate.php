<?php
namespace queues\resque\job;

/**
 * Exception to be thrown if while enqueuing a job it should not be created.
 *
 * @package  resque/job
 * @author   Chris Boulton <chris@bigcommerce.com>
 * @license  http://www.opensource.org/licenses/mit-license.php
 */
class ResqueJobDontCreate extends \Exception
{

}