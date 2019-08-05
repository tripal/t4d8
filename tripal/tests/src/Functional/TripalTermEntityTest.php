<?php

namespace Drupal\Tests\tripal\Functional;

use Drupal\tripal\Entity\TripalTerm;
use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Url;

/**
 * Tests the basic functions of the TripalTerm Entity Type.
 *
 * @ingroup tripal
 *
 * @group TripalTerm
 * @group entities
 */
class TripalTermEntityTest extends BrowserTestBase {

  // protected $htmlOutputEnabled = TRUE;

  public static $modules = ['tripal', 'block', 'field_ui'];

  /**
   * Basic tests for Content Entity Example.
   */
  public function testTripalTermEntity() {
    $assert = $this->assertSession();

    $web_user = $this->drupalCreateUser([
      'view controlled vocabulary term entities',
      'add controlled vocabulary term entities',
      'edit controlled vocabulary term entities',
      'delete controlled vocabulary term entities',
      'administer controlled vocabulary term entities',
      'access administration pages',
      'access controlled vocabulary term overview',
    ]);

    // Anonymous User should not see the tripal vocab listing.
    // Thus we will try to go to the listing... then check we can't.
    $this->drupalGet('admin/structure/tripal_term');
    $assert->pageTextContains('Access denied');

    $this->drupalLogin($web_user);

    // TripalTerm Listing.
    //-------------------------------------------

    // First check that the listing shows up in the structure menu.
    $this->drupalGet('admin/structure');
    $assert->linkExists('Tripal Controlled Vocabulary Terms');
    $this->clickLink('Tripal Controlled Vocabulary Terms');

    // Web_user user has the right to view listing.
    // We should now be on admin/structure/tripal_term.
    // thus check for the expected title.
    $assert->pageTextContains('Tripal Controlled Vocabulary Terms');

    // We start out without any content... thus check we are told there
    // are no Controlled Vocabulary Terms.
    $msg = 'There are no tripal controlled vocabulary term entities yet.';
    $assert->pageTextContains($msg);

    // TripalTerm Add Form.
    //-------------------------------------------

    // Check that there is an "Add Vocabulary" link on the listing page.
    // @todo fails $assert->linkExists('Add Vocabulary');

    // Go to the Add Vocabulary page.
    // @todo fails $this->clickLink('Add Vocabulary');
    $this->drupalGet('admin/structure/tripal_term/add');
    // We should now be on admin/structure/tripal_term/add.
    $assert->pageTextContains('Add tripal controlled vocabulary term');
    $assert->fieldExists('Tripal Controlled Vocabulary');
    $assert->fieldValueEquals('Tripal Controlled Vocabulary', '');
    $assert->fieldExists('Accession');
    $assert->fieldValueEquals('Accession', '');
    $assert->fieldExists('Term Name');
    $assert->fieldValueEquals('Term Name', '');

    // Now fill out the form and submit.
    // Post content, save an instance. Go to the new page after saving.
    $vocab = TripalTerm::create();
    print_r($vocab);
    $name = 'test ' . date('Ymd');
    $accession = uniqid();
    $add = [
      'vocab_id' => $vocab->getID(),
      'accession' => $accession,
      'name' => $name,
    ];
    $this->drupalPostForm(NULL, $add, 'Save');
    $assert->pageTextContains('Created the ' . $name . ' controlled vocabulary term.');

    // Then go back to the listing.
    $this->drupalGet('admin/structure/tripal_term');

    // There should now be entities thus we shouldn't see the empty msg.
    $msg = 'There are no tripal controlled vocabulary term entities yet.';
    $assert->pageTextNotContains($msg);

    // We should also see our new record listed with edit/delete links.
    $assert->pageTextContains($name);
    $assert->linkExists('Edit');
    $assert->linkExists('Delete');

    // TripalTerm Edit Form.
    //-------------------------------------------

    // Go to the edit form for our new entity.
    $this->clickLink('Edit');
    // We should now be on admin/structure/tripal_term/{tripal_term}/edit.
    $assert->pageTextContains('Edit');
    $assert->fieldExists('controlled vocabulary term Name');
    $assert->fieldValueEquals('controlled vocabulary term Name', $name);

    // Now fill out the form and submit.
    // Post content, save the instance.
    $new_vocab_name = $name . ' CHANGED';
    $edit = [
      'vocabulary' => $new_vocab_name,
    ];
    $this->drupalPostForm(NULL, $edit, 'Save');
    $assert->pageTextContains('Saved the ' . $new_vocab_name . ' controlled vocabulary term.');

    // Then go back to the listing.
    $this->drupalGet('admin/structure/tripal_term');
    // We should also see our new record listed with edit/delete links.
    $assert->pageTextContains($new_vocab_name);
    $assert->linkExists('Edit');
    $assert->linkExists('Delete');

    // TripalTerm Delete Form.
    //-------------------------------------------
    // Go to the edit form for our new entity.
    $this->clickLink('Delete');

    // Check that we get the confirmation form.
    $msg = 'Are you sure you want to delete the tripal controlled vocabulary term';
    $assert->pageTextContains($msg);
    $assert->pageTextContains('This action cannot be undone.');
    $assert->buttonExists('Delete');
    // @todo fails $assert->buttonExists('Cancel');

    // First we cancel and check the record is not deleted.
    // @todo fails $this->drupalPostForm(NULL, [], 'edit_cancel');
    $this->drupalGet('admin/structure/tripal_term');
    $assert->pageTextContains($new_vocab_name);
    $assert->linkExists('Edit');
    $assert->linkExists('Delete');

    // Now we delete the record.
    $this->clickLink('Delete');
    $this->drupalPostForm(NULL, [], 'Delete');
    $msg = 'The tripal controlled vocabulary term has been deleted.';
    $assert->pageTextContains($msg);
  }

  /**
   * Test all paths exposed by the module, by permission.
   */
  public function testPaths() {
    $assert = $this->assertSession();

    // Generate a vocab so that we can test the paths against it.
    $vocab = TripalTerm::create([
      'vocabulary' => 'somename',
    ]);
    $vocab->save();

    // Gather the test data.
    $data = $this->providerTestPaths($vocab->id());

    // Run the tests.
    foreach ($data as $datum) {
      // drupalCreateUser() doesn't know what to do with an empty permission
      // array, so we help it out.
      if ($datum[2]) {
        $user = $this->drupalCreateUser([
          'access administration pages',
          $datum[2]
        ]);
        $this->drupalLogin($user);
      }
      else {
        $user = $this->drupalCreateUser();
        $this->drupalLogin($user);
      }
      $this->drupalGet($datum[1]);
      $assert->statusCodeEquals($datum[0]);
    }
  }

  /**
   * Data provider for testPaths.
   *
   * @param int $tripal_term_id
   *   The id of an existing TripalTerm entity.
   *
   * @return array
   *   Nested array of testing data. Arranged like this:
   *   - Expected response code.
   *   - Path to request.
   *   - Permission for the user.
   */
  protected function providerTestPaths($vocab_id) {
    return [
      [
        200,
        '/admin/structure/tripal_term/' . $vocab_id,
        'view controlled vocabulary term entities',
      ],
      [
        403,
        '/admin/structure/tripal_term/' . $vocab_id,
        '',
      ],
      [
        200,
        '/admin/structure/tripal_term',
        'view controlled vocabulary term entities',
      ],
      [
        403,
        '/admin/structure/tripal_term',
        '',
      ],
      [
        200,
        '/admin/structure/tripal_term/add',
        'add controlled vocabulary term entities',
      ],
      [
        403,
        '/admin/structure/tripal_term/add',
        '',
      ],
      [
        200,
        '/admin/structure/tripal_term/' . $vocab_id . '/edit',
        'edit controlled vocabulary term entities',
      ],
      [
        403,
        '/admin/structure/tripal_term/' . $vocab_id . '/edit',
        '',
      ],
      [
        200,
        '/admin/structure/tripal_term/' . $vocab_id . '/delete',
        'delete controlled vocabulary term entities',
      ],
      [
        403,
        '/admin/structure/tripal_term/' . $vocab_id . '/delete',
        '',
      ],
    ];
  }

}
