<?php
namespace queues\resque\job;

/**
 * Runtime exception class for a job that does not exit cleanly.
 *
 * @package  resque/job
 * @author   Chris Boulton <chris@bigcommerce.com>
 * @license  http://www.opensource.org/licenses/mit-license.php
 */
class ResqueJobDirtyExitException extends \RuntimeException
{

}