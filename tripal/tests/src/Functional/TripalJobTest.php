<?php

namespace Drupal\Tests\tripal\Functional;

use Drupal\Tests\BrowserTestBase;

use Drupal\tripal\Services\TripalJob;

function TripalJobTestCallback() {
}

/**
 */
class TripalJobTest extends BrowserTestBase {
    protected $defaultTheme = 'stable';
    protected static $modules = ['tripal'];

	/**
	 */
	public function testCreate() {
        $job = new TripalJob();
        $job->create(
            array(
                "job_name" => "Test Job"
                ,"modulename" => "tripal"
                ,"callback" => "\\Drupal\\Tests\\tripal\\Functional\\TripalJobTestCallback"
                ,"arguments" => array()
                ,"uid" => 66
            )
        );
        $id = $job->getJobID();
        $submitTime = $job->getSubmitTime();
        $sql = "SELECT j.* FROM {tripal_jobs} j WHERE j.job_id = :job_id";
        $args = [":job_id" => $id];
        $database = \Drupal::database();
        $query = $database->query($sql,$args);
        $jobData = $query->fetchAssoc();
        $this->assertTrue(is_array($jobData));
        $this->assertArrayHasKey("job_id",$jobData);
        $this->assertArrayHasKey("uid",$jobData);
        $this->assertArrayHasKey("job_name",$jobData);
        $this->assertArrayHasKey("modulename",$jobData);
        $this->assertArrayHasKey("callback",$jobData);
        $this->assertArrayHasKey("arguments",$jobData);
        $this->assertArrayHasKey("progress",$jobData);
        $this->assertArrayHasKey("status",$jobData);
        $this->assertArrayHasKey("submit_date",$jobData);
        $this->assertArrayHasKey("start_time",$jobData);
        $this->assertArrayHasKey("end_time",$jobData);
        $this->assertArrayHasKey("error_msg",$jobData);
        $this->assertArrayHasKey("pid",$jobData);
        $this->assertArrayHasKey("priority",$jobData);
        $this->assertArrayHasKey("mlock",$jobData);
        $this->assertArrayHasKey("lock",$jobData);
        $this->assertArrayHasKey("includes",$jobData);
        $this->assertTrue($jobData["job_id"] == $id);
        $this->assertTrue($jobData["uid"] == 66);
        $this->assertTrue($jobData["job_name"] == "Test Job");
        $this->assertTrue($jobData["modulename"] == "tripal");
        $this->assertTrue($jobData["callback"] == "\\Drupal\\Tests\\tripal\\Functional\\TripalJobTestCallback");
        $this->assertTrue($jobData["arguments"] == "a:0:{}");
        $this->assertTrue($jobData["progress"] == 0);
        $this->assertTrue($jobData["status"] == "Waiting");
        $this->assertTrue($jobData["submit_date"] == $submitTime);
        $this->assertTrue($jobData["includes"] == "a:0:{}");
    }
}
