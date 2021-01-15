<?php

namespace Drupal\Tests\tripal\Functional;

use Drupal\tripal\Entity\TripalVocab;
use Drupal\tripal\Entity\TripalTerm;
use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Url;

/**
 * Tests the basic functions of the Job Management
 *
 * @group3 Job Management
 */
class TripalJobManagementTest extends BrowserTestBase {

  // Part1: test UI and job list page
  public function testJobListing(){

    $assert = $this->assertSession();

    $web_user = $this->drupalCreateUser();

    // anonymous user should have no access
    $this->drupalGet('admin/tripal/tripal_jobs');
    $assert->pageTextContains('Access denied');

    $this->drupalLogin($web_user);

    $this->drupalGet('admin/tripal');
    $assert->linkExists('Jobs');
    $this->clickLink('Jobs');

    $assert->pageTextContains('Jobs');

    $assert->fieldExists('Job Name Contains');
    $assert->fieldValueEquals('Job Name Contains', '');
    $assert->fieldExists('Submitting Module');
    $assert->fieldValueEquals('Submitting Module', '');
    $assert->fieldExists('Job Status');
    $assert->fieldValueEquals('Job Status', '');
    $assert->fieldExists('Submitter');
    $assert->fieldValueEquals('Submitter', '');
    // test for illustration message
    $msg = 'Waiting jobs are executed first by priority level (the lower the number the higher the priority) and second by the order they were entered.';
    $assert->pageTextContains($msg);
  }

  public function testJobManagementAPI(){
    //create a job manually, test if it exists, then able to find in search results
    /**
     * Tripal Jobs API:
     *
     * tripal_add_job ($job_name, $modulename, $callback, $arguments, $uid, $priority=10)
     * tripal_jobs_check_running ()
     * tripal_jobs_get_start_time ($job)
     * tripal_jobs_get_end_time ($job)
     * tripal_jobs_rerun ($job_id, $goto_jobs_page=TRUE)
     * tripal_jobs_cancel ($job_id, $redirect=TRUE)
     * tripal_jobs_launch ($do_parallel=0, $job_id=NULL)
     * tripal_get_module_active_jobs ($modulename)
     * tripal_jobs_get_submit_date ($job)
     */
    //??? creating a job by using tripal_add_job ($job_name, $modulename, $callback, $arguments, $uid, $priority=10)
    $test_one_job_detail = [];
  }




 // Part2: test creating job

}
